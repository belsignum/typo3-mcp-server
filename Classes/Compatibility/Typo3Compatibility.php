<?php

declare(strict_types=1);

namespace Hn\McpServer\Compatibility;

use Hn\McpServer\Compatibility\Schema\LegacyTcaSchemaFactory;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDateTimeFields;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single place for every API difference between TYPO3 12 and TYPO3 13/14.
 *
 * Upstream code only targets TYPO3 13+. The TYPO3 12 backport routes each
 * call that differs through this class, so the rest of the code base stays
 * as close to upstream as possible and a future upstream merge only touches
 * the call sites listed in BACKPORT.md.
 *
 * Remove this class (and Classes/Compatibility/) once TYPO3 12 support is dropped.
 */
final class Typo3Compatibility
{
    public static function isTypo3V12(): bool
    {
        return GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() < 13;
    }

    /**
     * TYPO3 13 introduced TYPO3\CMS\Core\Schema\TcaSchemaFactory. On TYPO3 12
     * a small read-only replacement with the same method subset is returned.
     *
     * @return \TYPO3\CMS\Core\Schema\TcaSchemaFactory|LegacyTcaSchemaFactory
     */
    public static function getTcaSchemaFactory(): object
    {
        if (class_exists(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class)) {
            return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class);
        }
        return GeneralUtility::makeInstance(LegacyTcaSchemaFactory::class);
    }

    /**
     * Build the full TCA. TYPO3 13 moved this into TcaFactory, TYPO3 12 still
     * populates $GLOBALS['TCA'] through ExtensionManagementUtility.
     */
    public static function loadTca(): array
    {
        if (class_exists(\TYPO3\CMS\Core\Configuration\Tca\TcaFactory::class)) {
            return GeneralUtility::getContainer()->get(\TYPO3\CMS\Core\Configuration\Tca\TcaFactory::class)->get();
        }
        ExtensionManagementUtility::loadBaseTca();
        return $GLOBALS['TCA'];
    }

    /**
     * Conflict mode "rename" for ResourceStorage::addFile(). TYPO3 13 uses a
     * backed enum, TYPO3 12 the string constant of an Enumeration class.
     *
     * @return \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior|string
     */
    public static function duplicationBehaviorRename(): mixed
    {
        if (enum_exists(\TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::class)) {
            return \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::RENAME;
        }
        return \TYPO3\CMS\Core\Resource\DuplicationBehavior::RENAME;
    }

    /**
     * Uid of the translation a language overlay was taken from, or null when the
     * row was not overlaid. TYPO3 13 marks every overlay with "_LOCALIZED_UID",
     * TYPO3 12 uses "_PAGES_OVERLAY_UID" for pages.
     */
    public static function overlayTranslationUid(array $overlaidRow): ?int
    {
        $uid = $overlaidRow['_LOCALIZED_UID'] ?? $overlaidRow['_PAGES_OVERLAY_UID'] ?? null;
        return $uid !== null ? (int)$uid : null;
    }

    /**
     * Integer value of a t3ver_state. VersionState is a backed enum since
     * TYPO3 13 and a class with int constants in TYPO3 12.
     *
     * @param string $name Case name, e.g. 'DELETE_PLACEHOLDER'
     */
    public static function versionStateValue(string $name): int
    {
        $state = constant(\TYPO3\CMS\Core\Versioning\VersionState::class . '::' . $name);
        return $state instanceof \BackedEnum ? (int)$state->value : (int)$state;
    }

    /**
     * Rebuild the reference index rows of one record in a workspace. TYPO3 13 takes
     * the workspace as argument of updateRefIndexTable(), TYPO3 12 as state set
     * with setWorkspaceId() beforehand.
     */
    public static function updateRefIndexTable(string $tableName, int $uid, int $workspaceId): void
    {
        $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        if (method_exists($referenceIndex, 'setWorkspaceId')) {
            $referenceIndex->setWorkspaceId($workspaceId);
            $referenceIndex->updateRefIndexTable($tableName, $uid);
            return;
        }
        $referenceIndex->updateRefIndexTable($tableName, $uid, false, $workspaceId);
    }

    /**
     * RelationHandler::initializeForField() exists since TYPO3 13. On TYPO3 12 the
     * same arguments are passed to start(), as the TYPO3 13.4 implementation does:
     * MM relations are stored for the version uid, all other relations for the
     * live uid (t3ver_oid) of a versioned record.
     *
     * @param array|string|int|null $baseRecordOrUid Record with "uid" (and "t3ver_oid") or its uid
     * @param string|int|float|array|null $currentValue Stored field value
     */
    public static function initializeRelationHandlerForField(
        RelationHandler $relationHandler,
        string $tableName,
        array $fieldConfiguration,
        array|string|int|null $baseRecordOrUid,
        string|int|float|array|null $currentValue = null
    ): void {
        if (method_exists($relationHandler, 'initializeForField')) {
            $relationHandler->initializeForField($tableName, $fieldConfiguration, $baseRecordOrUid, $currentValue);
            return;
        }

        $manyToManyConfiguration = $fieldConfiguration['MM'] ?? '';
        $recordUid = $baseRecordOrUid;
        if (is_array($baseRecordOrUid)) {
            $recordUid = (int)($baseRecordOrUid['uid'] ?? 0);
            if (!$manyToManyConfiguration
                && BackendUtility::isTableWorkspaceEnabled($tableName)
                && ($baseRecordOrUid['t3ver_oid'] ?? 0) > 0
            ) {
                $recordUid = (int)$baseRecordOrUid['t3ver_oid'];
            }
        }

        $relationHandler->registerNonTableValues = (bool)($fieldConfiguration['allowNonIdValues'] ?? false);
        $foreignTable = $fieldConfiguration['allowed'] ?? $fieldConfiguration['foreign_table'] ?? '';
        // type=file uses allowed AND foreign_table
        if (($fieldConfiguration['type'] ?? '') === 'file') {
            $foreignTable = $fieldConfiguration['foreign_table'];
        }
        $relationHandler->start(
            is_array($currentValue) ? implode(',', $currentValue) : (string)$currentValue,
            $foreignTable,
            $manyToManyConfiguration,
            $recordUid,
            $tableName,
            $fieldConfiguration
        );
    }

    /**
     * Form data providers of the tcaDatabaseRecord group, for NewRecordInitializer.
     *
     * NewRecordInitializer runs the providers up to DatabaseRowDateTimeFields and finds
     * the initializers of extensions by their "before" on that provider, as news 14
     * declares it for NewsRowInitializeNew (which fills "datetime"). Extensions for
     * TYPO3 12 register such initializers with "depends" on DatabaseRowInitializeNew
     * only (news 12), so on TYPO3 12 they would be left out. There each provider that
     * depends on DatabaseRowInitializeNew and does not itself need
     * DatabaseRowDateTimeFields gets that "before". No API tells the two conventions
     * apart, hence the version check.
     */
    public static function tcaDatabaseRecordProviders(): array
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];
        if (!self::isTypo3V12()) {
            return $providers;
        }

        $dateTimeFields = DatabaseRowDateTimeFields::class;
        foreach ($providers as $providerClassName => $providerConfig) {
            if ($providerClassName === $dateTimeFields
                || !in_array(DatabaseRowInitializeNew::class, $providerConfig['depends'] ?? [], true)
                || in_array($dateTimeFields, $providerConfig['before'] ?? [], true)
                || self::providerDependsOn($providers, $providerClassName, $dateTimeFields)
            ) {
                continue;
            }
            $providers[$providerClassName]['before'][] = $dateTimeFields;
        }
        return $providers;
    }

    private static function providerDependsOn(array $providers, string $providerClassName, string $dependency): bool
    {
        $seen = [];
        $pending = $providers[$providerClassName]['depends'] ?? [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current === $dependency) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($pending, ...($providers[$current]['depends'] ?? []));
        }
        return false;
    }
}

<?php

namespace MediaWiki\Extension\AspaklaryaImages\Hooks;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaUpdater implements LoadExtensionSchemaUpdatesHook {
    /**
     * @param DatabaseUpdater $updater
     */
    public function onLoadExtensionSchemaUpdates( $updater ) {
        $type = $updater->getDB()->getType();
        $updater->addExtensionTable(
            'ai_images',
            __DIR__ . '/../../db/' . $type . '/tables-generated.sql'
        );
    }
}
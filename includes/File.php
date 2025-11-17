<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\Title\Title;
use RuntimeException;

class File {

    private Title $title;

    public function __construct( string $title ) {
        $this->title = FileRepoFile::normalizeTitle( $title );
        if ( !$this->title || !$this->title->canExist()) {
            throw new RuntimeException( "`$title` is not a valid file title." );
        }
    }
}
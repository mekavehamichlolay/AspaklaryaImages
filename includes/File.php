<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\Title\Title;
use RuntimeException;

class File {

    public const NETFREE_KNOWN_BIT = 1;
    public const NETFREE_OPEN_BIT = 2;

    public const AUTHORIZED_KNOWN_BIT = 4;
    public const AUTHORIZED_OPEN_BIT = 8;

    private Title $title;

    public function __construct( string $title ) {
        $this->title = FileRepoFile::normalizeTitle( $title );
        if ( !$this->title || !$this->title->canExist()) {
            throw new RuntimeException( "`$title` is not a valid file title." );
        }
    }
}
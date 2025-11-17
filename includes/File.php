<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use RuntimeException;
use Wikimedia\Rdbms\ILoadBalancer;

class File {

    public const IMAGES_TABLE = 'ai_images';
    public const RESTRICTION = 'aspaklaryaimages-manage-status';
    public const NETFREE_KNOWN_BIT = 1;
    public const NETFREE_OPEN_BIT = 2;

    public const AUTHORIZED_KNOWN_BIT = 4;
    public const AUTHORIZED_OPEN_BIT = 8;

    private Title $title;
    private ILoadBalancer $loadBalancer;

    private int $statusBits = 0;

    public function __construct( ILoadBalancer $loadBalancer, string $title ) {
        $this->loadBalancer = $loadBalancer;
        $this->title = FileRepoFile::normalizeTitle( $title );
        if ( !$this->title || !$this->title->canExist()) {
            throw new RuntimeException( "`$title` is not a valid file title." );
        }
    }

    public function setStatusBits( int $bits ): void {
        $this->statusBits = $bits;
    }

    public function getStatusBits(): int {
        return $this->statusBits;
    }

    public function isNetfreeKnown( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::NETFREE_KNOWN_BIT ) !== 0 : ( $bit & self::NETFREE_KNOWN_BIT ) !== 0;
    }

    public function isNetfreeOpen( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::NETFREE_OPEN_BIT ) !== 0 : ( $bit & self::NETFREE_OPEN_BIT ) !== 0;
    }

    public function isAuthorizedKnown( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::AUTHORIZED_KNOWN_BIT ) !== 0 : ( $bit & self::AUTHORIZED_KNOWN_BIT ) !== 0;
    }

    public function isAuthorizedOpen( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::AUTHORIZED_OPEN_BIT ) !== 0 : ( $bit & self::AUTHORIZED_OPEN_BIT ) !== 0;
    }

    public function setNetfreeStatus ( bool $open ): void{
        $this->statusBits |= self::NETFREE_KNOWN_BIT;
        if ( $open ) {
            $this->statusBits |= self::NETFREE_OPEN_BIT;
        } else {
            $this->statusBits &= ~self::NETFREE_OPEN_BIT;
        }
    }
    public function setAuthorizedStatus ( bool $open ): void{
        $this->statusBits |= self::AUTHORIZED_KNOWN_BIT;

        if ( $open ) {
            $this->statusBits |= self::AUTHORIZED_OPEN_BIT;
        } else {
            $this->statusBits &= ~self::AUTHORIZED_OPEN_BIT;
        }
    }

    public function updateStatus( Authority $performer ): Status {
        return $this->saveStatus( $this->statusBits, $performer );
    }

    private function saveStatus( int $newStatusBits, Authority $performer ): Status {

        $readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}

        if ( !$performer->isAllowed( self::RESTRICTION ) ) {
            return Status::newFatal( wfMessage( 'aspaklaryaimages-manage-status-denied' ) );
        }

        $db = $this->loadBalancer->getConnection( DB_PRIMARY );

        $netfreeChange = false;
        $statusChange = false;
        $newRow = false;
        $oldStatusBits = 0;

        $currentStatus = $db->newSelectQueryBuilder()
            ->select( [ 'ai_id', 'ai_status' ] )
            ->from( 'ai_images' )
            ->where( [ 'ai_image_title' => $this->title->getDBKey() ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        
        if ( $currentStatus === false ) {
            $newRow = true;
            if ( $this->isNetfreeKnown( $newStatusBits ) ) {
                $netfreeChange = true;
            }
            if ( $this->isAuthorizedKnown( $newStatusBits ) ) {
                $statusChange = true;
            }
        } else {
            $oldStatusBits = (int)$currentStatus->ai_status;
            if ( $oldStatusBits === $newStatusBits ) {
                
            }
        }
        return Status::newGood();
    }
}
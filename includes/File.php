<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class File {

    public const IMAGES_TABLE = 'ai_images';
    public const RESTRICTION = 'aspaklaryaimages-manage-status';
    public const NETFREE_KNOWN_POSITION = 0;
    public const NETFREE_KNOWN_BIT = 1 << self::NETFREE_KNOWN_POSITION;
    public const NETFREE_OPEN_POSITION = 1;
    public const NETFREE_OPEN_BIT = 1 << self::NETFREE_OPEN_POSITION;

    public const AUTHORIZED_KNOWN_POSITION = 2;
    public const AUTHORIZED_KNOWN_BIT = 1 << self::AUTHORIZED_KNOWN_POSITION;
    public const AUTHORIZED_OPEN_POSITION = 3;
    public const AUTHORIZED_OPEN_BIT = 1 << self::AUTHORIZED_OPEN_POSITION;

    public const CACHE_TIME = 3600 * 24 * 30;

    private Title $title;
    private ILoadBalancer $loadBalancer;
    private WANObjectCache $cache;
    private string $cacheKey;

    private int $statusBits = 0;

    /**
     * @param ILoadBalancer $loadBalancer
     * @param WANObjectCache $cache
     * @param Title $title
     * @throws RuntimeException if the title is not a valid file title
     */
    public function __construct( ILoadBalancer $loadBalancer, WANObjectCache $cache, Title $title ) {
        
        $this->title = FileRepoFile::normalizeTitle( $title );
        if ( !$this->title || !$this->title->canExist()) {
            throw new RuntimeException( "`$title` is not a valid file title." );
        }
        $this->loadBalancer = $loadBalancer;
        $this->cache = $cache;
        $this->cacheKey = $this->cache->makeKey( 'aspaklarya-images', 'v1', $this->title->getDBKey() );
        $this->loadStatusBits();
    }

    public function loadStatusBits( bool $useCache = true ): self {
        if( $useCache ) {
            $this->statusBits = $this->cache->getWithSetCallback( $this->cacheKey, self::CACHE_TIME, function() {
                return $this->getFromDb();
            } );
            return $this;
        }
        $this->statusBits = $this->getFromDb();
        return $this;
    }

    public function getFromDb( $db = DB_REPLICA ): int {
        $dbConn = $this->loadBalancer->getConnection( $db );
        $row = $dbConn->newSelectQueryBuilder()
            ->select( [ 'ai_status' ] )
            ->from( self::IMAGES_TABLE )
            ->where( [ 'ai_image_title' => $this->title->getDBKey() ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row === false ) {
           return 0;
        } 
        return (int)$row->ai_status;
        
    }

    public function setStatusBits( int $bits ): self {
        $this->statusBits = $bits;
        return $this;
    }

    public function getStatusBits(): int {
        return $this->statusBits;
    }

    private function isNetfreeKnown( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::NETFREE_KNOWN_BIT ) !== 0 : ( $bit & self::NETFREE_KNOWN_BIT ) !== 0;
    }

    private function isNetfreeOpen( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::NETFREE_OPEN_BIT ) !== 0 : ( $bit & self::NETFREE_OPEN_BIT ) !== 0;
    }

    private function isAuthorizedKnown( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::AUTHORIZED_KNOWN_BIT ) !== 0 : ( $bit & self::AUTHORIZED_KNOWN_BIT ) !== 0;
    }

    private function isAuthorizedOpen( int|null $bit = null ): bool {
        return $bit === null ? ( $this->statusBits & self::AUTHORIZED_OPEN_BIT ) !== 0 : ( $bit & self::AUTHORIZED_OPEN_BIT ) !== 0;
    }

    public function getNetfreeStatus(): ?bool {
        if ( !$this->isNetfreeKnown() ) {
            return null;
        }
        return $this->isNetfreeOpen();
    }

    public function getAuthorizedStatus(): ?bool {
        if ( !$this->isAuthorizedKnown() ) {
            return null;
        }
        return $this->isAuthorizedOpen();
    }

    public function setNetfreeStatus ( bool $open ): self {
        $this->statusBits |= self::NETFREE_KNOWN_BIT;
        if ( $open ) {
            $this->statusBits |= self::NETFREE_OPEN_BIT;
        } else {
            $this->statusBits &= ~self::NETFREE_OPEN_BIT;
        }
        return $this;
    }
    public function setAuthorizedStatus ( bool $open ): self{
        $this->statusBits |= self::AUTHORIZED_KNOWN_BIT;

        if ( $open ) {
            $this->statusBits |= self::AUTHORIZED_OPEN_BIT;
        } else {
            $this->statusBits &= ~self::AUTHORIZED_OPEN_BIT;
        }
        return $this;
    }

    public function updateStatus( User $performer ): Status {
        if ( !$performer->isAllowed( self::RESTRICTION ) ) {
            return Status::newFatal( wfMessage( 'aspaklaryaimages-manage-status-unauthorized' ) );
        }
        return $this->saveStatus( $this->statusBits, $performer );
    }

    private function saveStatus( int $newStatusBits, User $performer ): Status {

        $readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}

        if ( !self::isValidBits( $newStatusBits ) ) {
            throw new RuntimeException( "unable to set $newStatusBits as it is not valid" );
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
            if ( $newStatusBits === 0 ) {
                return Status::newGood();
            }
            $newRow = true;
            if ( $this->isNetfreeKnown( $newStatusBits ) ) {
                $netfreeChange = true;
            }
            if ( $this->isAuthorizedKnown( $newStatusBits ) ) {
                $statusChange = true;
            }
        } else {
            $oldStatusBits = (int)$currentStatus->ai_status;
            $changedBits = $oldStatusBits ^ $newStatusBits;
            if ( $changedBits === 0 ) {
                return Status::newGood();
            }
            if ( ( ( $changedBits & self::NETFREE_KNOWN_BIT ) !== 0 ) || (  ( $changedBits & self::NETFREE_OPEN_BIT ) !== 0 ) ) {
                $netfreeChange = true;
            }
            if ( ( ( $changedBits & self::AUTHORIZED_KNOWN_BIT ) !== 0) || ( ( $changedBits & self::AUTHORIZED_OPEN_BIT ) !== 0 ) ) {
                $statusChange = true;
            }
            if ( !$netfreeChange && !$statusChange ) {
                return Status::newGood();
            }
        }
        $newId = 0;
        $logParameters = [];
        $logType = '';
        $logs = [];
        if ( $newRow ) {
            $db->insert( 
                self::IMAGES_TABLE, 
                [ 'ai_image_title' => $this->title->getDBKey(), 'ai_status' => $newStatusBits ], 
                __METHOD__ 
            );
            $newId = $db->insertId();
            $logType = 'insert';
            $logs[] = $this->publishLog( $logType, '', $performer, [ 'ai_id' => $newId ] );
            if ( $this->isNetfreeKnown( $newStatusBits ) ) {
                $logParameters['netfree'] = $this->isNetfreeOpen( $newStatusBits ) ? 'open' : 'blocked';
            }
            if ( $this->isAuthorizedKnown( $newStatusBits ) ) {
                $logParameters['authorized'] = $this->isAuthorizedOpen( $newStatusBits ) ? 'open' : 'blocked';
            }
        } else {
            $db->delete(
                self::IMAGES_TABLE,
                [ 'ai_id' => $currentStatus->ai_id ],
                __METHOD__,
            );
            if ( $newStatusBits !== 0 ) {
                $db->insert( 
                    self::IMAGES_TABLE, 
                    [ 'ai_image_title' => $this->title->getDBKey(), 'ai_status' => $newStatusBits ], 
                    __METHOD__ 
                );
                $newId = $db->insertId();
                $logType = 'update';
                if ( $netfreeChange ) {
                    if ( $this->isNetfreeKnown( $newStatusBits ) ) {
                        $logParameters['netfree'] = $this->isNetfreeOpen( $newStatusBits ) ? 'open' : 'blocked';
                    } else {
                        $logParameters['netfree'] = 'removed';
                    }
                }
                if ( $statusChange ) {
                    if ( $this->isAuthorizedKnown( $newStatusBits ) ) {
                        $logParameters['authorized'] = $this->isAuthorizedOpen( $newStatusBits ) ? 'open' : 'blocked';
                    } else {
                        $logParameters['authorized'] = 'removed';
                    }
                }
            } else {
                $logType = 'delete';
                $logs[] = $this->publishLog( $logType, '', $performer );
            }
        }
        
        foreach ( $logParameters as $key => $value ) {
            $logs[] = $this->publishLog( 'update', "$key-$value", $performer, [ 'ai_id' => $newId ] );
        }
        $this->invalidateCache();
        return Status::newGood( $logs );
    }

    private function publishLog( string $logType, string $parameter, User $performer, array $relations = [] ): int {
        $logEntry = new ManualLogEntry( 'aspaklaryaimages', $logType );
        $logEntry->setTarget( $this->title );
        $logEntry->setPerformer( $performer );
        $logEntry->setRelations( $relations );
        if ( $parameter !== '' ) {
            $logEntry->setParameters( [ '4::description' => $parameter ] );
        }
        return $logEntry->insert();
    }

    private function invalidateCache(): void {
        if ( !$this->cacheKey ) {
            return;
        }
        $this->cache->delete( $this->cacheKey );
    }

    private static function isValidBits( int $bits ):bool {
        return ( 
            ( ( $bits & self::NETFREE_KNOWN_BIT ) !== 0 || ( $bits & self::NETFREE_OPEN_BIT ) === 0 ) && 
            ( ( $bits & self::AUTHORIZED_KNOWN_BIT ) !== 0 || ( $bits & self::AUTHORIZED_OPEN_BIT ) === 0 ) 
        );
    }
}
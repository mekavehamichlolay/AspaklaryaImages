<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use InvalidArgumentException;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class FilesMannager {

    private ILoadBalancer $loadBalancer;
    private WANObjectCache $cache;

    /** @var Title[] */
    private array $titles = [];
    private const ORDER = [
            'blocked' => Constants::NETFREE_KNOWN_BIT,
            'open' => Constants::NETFREE_KNOWN_BIT | Constants::NETFREE_OPEN_BIT,
            'good' => Constants::AUTHORIZED_KNOWN_BIT | Constants::AUTHORIZED_OPEN_BIT,
            'bad' => Constants::AUTHORIZED_KNOWN_BIT,
        ];

    public function __construct( ILoadBalancer $loadBalancer, WANObjectCache $cache ) {
        $this->loadBalancer = $loadBalancer;
        $this->cache = $cache;
    }

    /**
     * @param string[] $titles
     * @return Title[]
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function setTitles( $titles ) {
        if ( !is_array( $titles ) ) {
            $titles = explode( '|', $titles );
        }
        if ( $titles === null ) {
            $titles = [];
        }
        if ( count( $titles ) === 0 ) {
            throw new InvalidArgumentException( 'Title parameter is required' );
        }
        $titles = array_unique( array_filter( $titles ) );
        foreach ( $titles as $titleText ) {
            $title = File::normalizeTitle( $titleText );
            if ( !$title ) {
                throw new RuntimeException( "Invalid title: $titleText" );
            }
            $this->titles[] = $title;
        }
        return $this->titles;
    }

    public function getTitles() {
        return $this->titles;
    }

    /**
     * @param ILoadBalancer $loadBalancer
     * @param WANObjectCache $cache
     * @param Authority $performer
     * @param string[] $titles
     * @param string|null $netfree
     * @param string|null $authorized
     * @return array<string, Status>
     * @throws InvalidArgumentException
     * @throws PermissionsError
     */
    public static function updateMultiStatus( ILoadBalancer $loadBalancer, WANObjectCache $cache, Authority $performer, array $titles, string $netfree, string $authorized ): array {
        if ( $netfree === '' && $authorized === '' ) {
            throw new InvalidArgumentException( 'You must set a value for one of $netfree or $authorized' );
        }
        $results = [];
        $netfreeBit = null;
        $authorizedBit = null;
        $delete = $netfree === null && $authorized === null;
        if ( !$delete ) {
            if ( $netfree !== '' ) {
                $netfreeBit = $netfree !== null ? self::ORDER[ $netfree ] : 0;
            }
            if ( $authorized !== '' ) {
                $authorizedBit = $authorized !== null ? self::ORDER[ $authorized ] : 0;
            }
        }
        $self = new self( $loadBalancer, $cache );
        $titles = $self->setTitles( $titles );
        $names = [];

        foreach ( $titles as $title ) {
            $names[] = $title->getDBkey();
        }

        $con = $self->loadBalancer->getConnection( DB_PRIMARY );
        $resultSet = $con->newSelectQueryBuilder()
            ->select( [ Constants::IMAGE_TABLE_ID_FIELD, Constants::IMAGE_TABLE_TITLE_FIELD, Constants::IMAGE_TABLE_STATUS_FIELD ] )
            ->from( Constants::IMAGES_TABLE )
            ->where( [ Constants::IMAGE_TABLE_TITLE_FIELD => $con->makeList( $names ) ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        if ( !$performer->authorizeAction( Constants::RESTRICTION ) ) {
            throw new PermissionsError( Constants::RESTRICTION );
        }

        $con->startAtomic( __METHOD__, $con::ATOMIC_CANCELABLE );
        /** @var array<int,array<string,string>> */
        $current = [];
        foreach ( $resultSet as $row ) {
            $current[ (int)$row->{Constants::IMAGE_TABLE_STATUS_FIELD} ] ??= [];
            $current[ (int)$row->{Constants::IMAGE_TABLE_STATUS_FIELD} ][  $row->{Constants::IMAGE_TABLE_ID_FIELD} ] = $row->{Constants::IMAGE_TABLE_TITLE_FIELD};
        }
        $newBitsToApply = [];
        if( !$delete ) {
            $existingBits = array_keys( $current );
            $changedBits = [];
            foreach ( $existingBits as $bit ) {
                if ( $netfreeBit !== null ) {
                    if ( $netfreeBit === 0 && Constants::isNetfreeKnown( $bit ) ) {
                        $newBit = $authorizedBit !== null ? ( $netfreeBit | $authorizedBit ) : ( $bit & ~0b11 );
                        $changedBits[ $newBit ] ??= [];
                        $changedBits[ $newBit ] +=  $current[ $bit ];
                        continue;
                    }
                    if ( !Constants::isNetfreeKnown( $bit ) || ( ( $bit & Constants::NETFREE_OPEN_BIT ) !== $netfreeBit )  ) {
                        $changedBits[] = $bit;
                        continue;
                    }
                }
                if ( $authorizedBit !== null ) {
                    if ( !Constants::isAuthorizedKnown( $bit ) || ( ( $bit & Constants::AUTHORIZED_OPEN_BIT ) !== $authorizedBit )  ) {
                        $changedBits[] = $bit;
                    }
                }
            }   
        }

        if ( count( $current ) > 0 && $delete ) {
            $con->newDeleteQueryBuilder()
                ->delete( Constants::IMAGES_TABLE )
                ->where( [ Constants::IMAGE_TABLE_ID_FIELD => $con->makeList( array_keys( array_merge( ...$current  ) ) ) ] )
                ->caller( __METHOD__ )
                ->execute();
            if ( $con->affectedRows() < count( $current ) ) {
                $con->cancelAtomic( __METHOD__ );
                return $results;
            }
        }

        return $results;
    }
}
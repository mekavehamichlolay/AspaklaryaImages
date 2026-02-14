<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use InvalidArgumentException;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Permissions\Authority;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IReadableDatabase;

class FilesMannager {

	private ILoadBalancer $loadBalancer;
	private WANObjectCache $cache;

	/** @var Title[] */
	private array $titles = [];

	private const NETFREE_ORDER = [
		'blocked' => Constants::NETFREE_KNOWN_BIT,
		'open'    => Constants::NETFREE_KNOWN_BIT | Constants::NETFREE_OPEN_BIT,
	];

	private const AUTHORIZED_ORDER = [
		'blocked' => Constants::AUTHORIZED_KNOWN_BIT,
		'open'    => Constants::AUTHORIZED_KNOWN_BIT | Constants::AUTHORIZED_OPEN_BIT,
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
			throw new InvalidArgumentException( 'Titles parameter is required' );
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
	 * @param string|null $netfree open|blocked or empty string to leave unchanged, null to delete
	 * @param string|null $authorized open|blocked or empty string to leave unchanged, null to delete
	 * @return array<string,Status>
	 * @throws InvalidArgumentException
	 * @throws PermissionsError
	 * @throws RuntimeException
	 */
	public static function updateMultiStatus( ILoadBalancer $loadBalancer, WANObjectCache $cache, Authority $performer, array $titles, string|null $netfree, string|null $authorized ): array {
		if ( $netfree === '' && $authorized === '' ) {
			throw new InvalidArgumentException( 'You must set a value for one of $netfree or $authorized' );
		}
		$netfreeBit = null; // null means no change, 0 for delete
		$authorizedBit = null; // null means no change, 0 for delete
		$delete = $netfree === null && $authorized === null;
		if ( !$delete ) {
			if ( $netfree !== '' ) {
				$netfreeBit = $netfree !== null ? self::NETFREE_ORDER[ $netfree ] : 0;
			}
			if ( $authorized !== '' ) {
				$authorizedBit = $authorized !== null ? self::AUTHORIZED_ORDER[ $authorized ] : 0;
			}
		}
		$self = new self( $loadBalancer, $cache );
		try {
			$titles = $self->setTitles( $titles );
		} catch ( InvalidArgumentException $e ) {
			return [ Status::newFatal( $e->getMessage() ) ];
		} catch ( RuntimeException $e ) {
			return [ Status::newFatal( $e->getMessage() ) ];
		}

		/** @var array<string,bool> */
		$names = [];

		foreach ( $titles as $title ) {
			$names[ $title->getDBkey() ] = true;
		}

		$con = $self->loadBalancer->getConnection( DB_PRIMARY );

		$transaction = $con->startAtomic( __METHOD__, $con::ATOMIC_CANCELABLE );
		$success = false;
		$con->onTransactionResolution( function () use ( $titles, $self ) {
			foreach ( $titles as $title ) {
				$cacheKey = Constants::makeCacheKey( $self->cache, $title->getDBkey() );
				$self->cache->delete( $cacheKey );
			}
			return true;	
		} );
		try {
			$resultSet = $con->newSelectQueryBuilder()
				->forUpdate()
				->select( [ Constants::IMAGE_TABLE_ID_FIELD, Constants::IMAGE_TABLE_TITLE_FIELD, Constants::IMAGE_TABLE_STATUS_FIELD ] )
				->from( Constants::IMAGES_TABLE )
				->where( [ Constants::IMAGE_TABLE_TITLE_FIELD => array_keys( $names ) ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			/** @var array<int,array<string,string>> */
			$current = [];
			foreach ( $resultSet as $row ) {
				if ( $delete ) {
					$current[ $row->{Constants::IMAGE_TABLE_ID_FIELD} ] = $row->{Constants::IMAGE_TABLE_TITLE_FIELD};
					continue;
				}
				$current[ (int)$row->{Constants::IMAGE_TABLE_STATUS_FIELD} ] ??= [];
				$current[ (int)$row->{Constants::IMAGE_TABLE_STATUS_FIELD} ][  $row->{Constants::IMAGE_TABLE_ID_FIELD} ] = $row->{Constants::IMAGE_TABLE_TITLE_FIELD};
				unset( $names[ $row->{Constants::IMAGE_TABLE_TITLE_FIELD} ] );
			}

			if ( !$performer->authorizeAction( Constants::RESTRICTION ) ) {
				throw new PermissionsError( Constants::RESTRICTION );
			}

			if ( $delete ) {
				if ( count( $current ) === 0 ) {
					$success = true;
					return [ Status::newGood( 'No entries found for the specified titles' ) ];
				}
				$con->newDeleteQueryBuilder()
					->delete( Constants::IMAGES_TABLE )
					->where( [ Constants::IMAGE_TABLE_ID_FIELD => array_keys( $current ) ] )
					->caller( __METHOD__ )
					->execute();
				if ( $con->affectedRows() < count( $current ) ) {
					return [ Status::newFatal( 'Failed to delete all specified entries' ) ];
				}
				$success = true;
				return array_fill_keys( array_values( $current ), Status::newGood() );
			}
			$newData = [];
			if ( count( $names ) > 0 ) {
				$newData[ ( $netfreeBit ?? 0 ) | ( $authorizedBit ?? 0 ) ] = array_keys( $names );
			}
			$existingBits = array_keys( $current );
			$changedBits = [];
			$toDelete = [];
			foreach ( $existingBits as $bit ) {
				$newBit = null;
				if ( $netfreeBit !== null ) {
					$newBit = self::changeOnlySpecificBits( $bit, $netfreeBit, 1 );
				}
				if ( $authorizedBit !== null ) {
					$newBit = self::changeOnlySpecificBits( $newBit ?? $bit, $authorizedBit, 2 );
				}
				if ( $newBit !== $bit ) {
					if ( $newBit === 0 ) {
						$toDelete = array_merge( $toDelete, array_keys( $current[ $bit ] ) );
						continue;
					}
					$changedBits[ $bit ] = $newBit;
					$newData[ $newBit ] ??= [];
					$newData[ $newBit ] = array_merge( $newData[ $newBit ], array_values( $current[ $bit ] ) );
				}
			}

			foreach ( $changedBits as $bit => $_ ) {
				$toDelete = array_merge( $toDelete, array_keys( $current[ $bit ] ) );
			}
			if ( count( $toDelete ) > 0 ) {
				 $con->newDeleteQueryBuilder()
					->delete( Constants::IMAGES_TABLE )
					->where( [ Constants::IMAGE_TABLE_ID_FIELD => $toDelete ] )
					->caller( __METHOD__ )
					->execute();
				if ( $con->affectedRows() < count( $toDelete ) ) {
					return [ Status::newFatal( 'Failed to delete all specified entries' ) ];
				}
			}
			if ( count( $newData ) === 0 ) {
				$success = true;
				return array_fill_keys( array_values( array_merge( ...array_values( $current ) ) ), Status::newGood() );
			}
			$toSet = [];
			foreach ( $newData as $bit => $titles ) {
				foreach ( $titles as $title ) {
					$toSet[] = [
						Constants::IMAGE_TABLE_TITLE_FIELD => $title,
						Constants::IMAGE_TABLE_STATUS_FIELD => $bit,
					];
				}
			}
			// if ( count( $toSet ) === 0 ) {
				// return $toSet;
			// }
			$con->newInsertQueryBuilder()
				->insertInto( Constants::IMAGES_TABLE )
				->rows( $toSet )
				->caller( __METHOD__ )
				->execute();
			if ( $con->affectedRows() < count( $toSet ) ) {
				return [ Status::newFatal( 'Failed to insert all specified entries' ) ];
			}
			$success = true;
			return array_fill_keys( array_values( array_merge( ...array_values( $newData ) ) ), Status::newGood() );

		} finally {
			if ( $success ) {
				$con->endAtomic( __METHOD__ );
			} else {
				$con->cancelAtomic( __METHOD__, $transaction );
			}
		}
	}

	/**
	 * Change only the specific bits related to netfree or authorized, leaving the other bits unchanged.
	 * @param int $oldBit The original bit value.
	 * @param int $newBit The new bit value to set (should be one of the values from self::NETFREE_ORDER or self::AUTHORIZED_ORDER, depending on $pos).
	 * @param int $pos The position of the bits to change (1 for netfree, 2 for authorized).
	 * @return int The modified bit value with only the specified bits changed.
	 */
	private static function changeOnlySpecificBits( int $oldBit, int $newBit, int $pos ): int {
		if ( $pos === 1 ) {
			$tempBit = $oldBit & ~0b11; // Clear netfree bits
			$tempBit |= $newBit; // Set new netfree bits
			return $tempBit & 0x0f;
		}
		$tempBit = $oldBit & ~0b1100; // Clear authorized bits
		$tempBit |= $newBit; // Set new authorized bits
		return $tempBit & 0x0f;
	}
}

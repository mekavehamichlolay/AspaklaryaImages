<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\Exception\PermissionsError;
use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class File {

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
		if ( !$this->title || !$this->title->canExist() ) {
			throw new RuntimeException( "`$title` is not a valid file title." );
		}
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
		$this->cacheKey = $this->makeCacheKey();
		$this->loadStatusBits();
	}

	private function makeCacheKey(): string {
		return Constants::makeCacheKey( $this->cache, $this->title->getDBKey() );
	}

	public function getCacheKey(): string {
		return $this->cacheKey;
	}

	public function loadStatusBits( bool $useCache = true ): self {
		if ( $useCache ) {
			$this->statusBits = $this->cache->getWithSetCallback( $this->cacheKey, Constants::IMAGE_CACHE_TIME, function () {
				return $this->getFromDb();
			} );
			return $this;
		}
		$this->statusBits = $this->getFromDb( DB_PRIMARY );
		return $this;
	}

	public function getFromDb( $db = DB_REPLICA ): int {
		$dbConn = $this->loadBalancer->getConnection( $db );
		$row = $dbConn->newSelectQueryBuilder()
			->select( [ Constants::IMAGE_TABLE_STATUS_FIELD ] )
			->from( Constants::IMAGES_TABLE )
			->where( [ Constants::IMAGE_TABLE_TITLE_FIELD => $this->title->getDBKey() ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( $row === false ) {
		   return 0;
		}
		return (int)$row->{Constants::IMAGE_TABLE_STATUS_FIELD};
	}

	public function setStatusBits( int $bits ): self {
		$this->statusBits = $bits;
		return $this;
	}

	public function getStatusBits(): int {
		return $this->statusBits;
	}

	public function getNetfreeStatus(): ?bool {
		if ( !Constants::isNetfreeKnown( $this->statusBits ) ) {
			return null;
		}
		return Constants::isNetfreeOpen( $this->statusBits );
	}

	public function getAuthorizedStatus(): ?bool {
		if ( !Constants::isAuthorizedKnown( $this->statusBits ) ) {
			return null;
		}
		return Constants::isAuthorizedOpen( $this->statusBits );
	}

	public function setNetfreeStatus( bool $open ): self {
		$this->statusBits |= Constants::NETFREE_KNOWN_BIT;
		if ( $open ) {
			$this->statusBits |= Constants::NETFREE_OPEN_BIT;
		} else {
			$this->statusBits &= ~Constants::NETFREE_OPEN_BIT;
		}
		return $this;
	}

	public function deleteNetfreeStatus(): self {
		$this->statusBits &= ~( Constants::NETFREE_KNOWN_BIT | Constants::NETFREE_OPEN_BIT );
		return $this;
	}

	public function setAuthorizedStatus( bool $open ): self {
		$this->statusBits |= Constants::AUTHORIZED_KNOWN_BIT;

		if ( $open ) {
			$this->statusBits |= Constants::AUTHORIZED_OPEN_BIT;
		} else {
			$this->statusBits &= ~Constants::AUTHORIZED_OPEN_BIT;
		}
		return $this;
	}

	public function deleteAuthorizedStatus(): self {
		$this->statusBits &= ~( Constants::AUTHORIZED_KNOWN_BIT | Constants::AUTHORIZED_OPEN_BIT );
		return $this;
	}

	public function updateStatus( User $performer ): Status {
		if ( !$performer->authorizeAction( Constants::RESTRICTION ) ) {
			throw new PermissionsError( Constants::RESTRICTION );
		}
		return $this->saveStatus( $this->statusBits, $performer );
	}

	private function saveStatus( int $newStatusBits, User $performer ): Status {
		$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}

		if ( !Constants::isValidBits( $newStatusBits ) ) {
			throw new RuntimeException( "unable to set $newStatusBits as it is not valid" );
		}

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );

		$netfreeChange = false;
		$statusChange = false;
		$newRow = false;
		$oldStatusBits = 0;

		$currentStatus = $db->newSelectQueryBuilder()
			->select( [ Constants::IMAGE_TABLE_ID_FIELD, Constants::IMAGE_TABLE_STATUS_FIELD ] )
			->from( Constants::IMAGES_TABLE )
			->where( [ Constants::IMAGE_TABLE_TITLE_FIELD => $this->title->getDBKey() ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $currentStatus === false ) {
			if ( $newStatusBits === 0 ) {
				return Status::newGood();
			}
			$newRow = true;
			if ( Constants::isNetfreeKnown( $newStatusBits ) ) {
				$netfreeChange = true;
			}
			if ( Constants::isAuthorizedKnown( $newStatusBits ) ) {
				$statusChange = true;
			}
		} else {
			$oldStatusBits = (int)$currentStatus->{Constants::IMAGE_TABLE_STATUS_FIELD};
			$changedBits = $oldStatusBits ^ $newStatusBits;
			if ( $changedBits === 0 ) {
				return Status::newGood();
			}
			if ( ( ( $changedBits & Constants::NETFREE_KNOWN_BIT ) !== 0 ) || ( ( $changedBits & Constants::NETFREE_OPEN_BIT ) !== 0 ) ) {
				$netfreeChange = true;
			}
			if ( ( ( $changedBits & Constants::AUTHORIZED_KNOWN_BIT ) !== 0 ) || ( ( $changedBits & Constants::AUTHORIZED_OPEN_BIT ) !== 0 ) ) {
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
				Constants::IMAGES_TABLE,
				[ Constants::IMAGE_TABLE_TITLE_FIELD => $this->title->getDBKey(), Constants::IMAGE_TABLE_STATUS_FIELD => $newStatusBits ],
				__METHOD__
 			);
			$newId = $db->insertId();
			$logType = 'insert';
			// $logs[] = self::publishLog( $this->title, $logType, '', $performer, [ Constants::IMAGE_TABLE_ID_FIELD => $newId ] );
			if ( Constants::isNetfreeKnown( $newStatusBits ) ) {
				$logParameters['netfree'] = Constants::isNetfreeOpen( $newStatusBits ) ? 'open' : 'blocked';
			}
			if ( Constants::isAuthorizedKnown( $newStatusBits ) ) {
				$logParameters['authorized'] = Constants::isAuthorizedOpen( $newStatusBits ) ? 'open' : 'blocked';
			}
		} else {
			$db->delete(
				Constants::IMAGES_TABLE,
				[ Constants::IMAGE_TABLE_ID_FIELD => $currentStatus->{Constants::IMAGE_TABLE_ID_FIELD} ],
				__METHOD__,
			);
			if ( $newStatusBits !== 0 ) {
				$db->insert(
					Constants::IMAGES_TABLE,
					[ Constants::IMAGE_TABLE_TITLE_FIELD => $this->title->getDBKey(), Constants::IMAGE_TABLE_STATUS_FIELD => $newStatusBits ],
					__METHOD__
 				);
				$newId = $db->insertId();
				$logType = 'update';
				if ( $netfreeChange ) {
					if ( Constants::isNetfreeKnown( $newStatusBits ) ) {
						$logParameters['netfree'] = Constants::isNetfreeOpen( $newStatusBits ) ? 'open' : 'blocked';
					} else {
						$logParameters['netfree'] = 'removed';
					}
				}
				if ( $statusChange ) {
					if ( Constants::isAuthorizedKnown( $newStatusBits ) ) {
						$logParameters['authorized'] = Constants::isAuthorizedOpen( $newStatusBits ) ? 'open' : 'blocked';
					} else {
						$logParameters['authorized'] = 'removed';
					}
				}
			} else {
				$logType = 'delete';
				$logs[] = self::publishLog( $this->title, $logType, '', $performer );
			}
		}

		foreach ( $logParameters as $key => $value ) {
			$logs[] = self::publishLog( $this->title, $logType || 'update', "$key-$value", $performer, [ Constants::IMAGE_TABLE_ID_FIELD => $newId ] );
		}
		$this->invalidateCache();
		return Status::newGood( $logs );
	}

	public static function publishLog( LinkTarget|PageReference $title, string $logType, string $parameter, UserIdentity $performer, array $relations = [] ): int {
		$logEntry = new ManualLogEntry( Constants::LOG_NAME, $logType );
		$logEntry->setTarget( $title );
		$logEntry->setPerformer( $performer );
		$logEntry->setRelations( $relations );
		if ( $parameter !== '' ) {
			$logEntry->setParameters( [ '4::description' => wfMessage( $parameter ) ] );
		}
		return $logEntry->insert();
	}

	private function invalidateCache(): void {
		if ( !$this->cacheKey ) {
			return;
		}
		$this->cache->delete( $this->cacheKey );
	}
}

<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use Wikimedia\ObjectCache\WANObjectCache;

class Constants {

    public const IMAGES_TABLE = 'ai_images';

    public const IMAGE_TABLE_ID_FIELD = 'ai_id';
    public const IMAGE_TABLE_TITLE_FIELD = 'ai_image_title';
    public const IMAGE_TABLE_STATUS_FIELD = 'ai_status';

    public const RESTRICTION = 'aspaklaryaimages-manage-status';
    
    public const NETFREE_OPTIONS = [ '', 'none', 'open', 'blocked' ];
    public const AUTHORIZED_OPTIONS = [ '', 'none', 'good', 'bad' ];

    public const NETFREE_KNOWN_POSITION = 0;
    public const NETFREE_KNOWN_BIT = 1 << self::NETFREE_KNOWN_POSITION;
    public const NETFREE_OPEN_POSITION = 1;
    public const NETFREE_OPEN_BIT = 1 << self::NETFREE_OPEN_POSITION;

    public const AUTHORIZED_KNOWN_POSITION = 2;
    public const AUTHORIZED_KNOWN_BIT = 1 << self::AUTHORIZED_KNOWN_POSITION;
    public const AUTHORIZED_OPEN_POSITION = 3;
    public const AUTHORIZED_OPEN_BIT = 1 << self::AUTHORIZED_OPEN_POSITION;

    public const IMAGE_CACHE_TIME = 3600 * 24 * 30;

    public const CACHE_KEY_PREFIX = 'aspaklaryaimages';
    public const CACHE_KEY_VERSION = 'v1';

    public static function makeCacheKey( WANObjectCache $cache, string $title ): string {
        return $cache->makeKey( self::CACHE_KEY_PREFIX, self::CACHE_KEY_VERSION, $title );
    }

    public static function isNetfreeKnown( int $bit  ): bool {
        return  ( $bit & self::NETFREE_KNOWN_BIT ) !== 0;
    }

    public static function isNetfreeOpen( int $bit ): bool {
        return  ( $bit & self::NETFREE_OPEN_BIT ) !== 0;
    }

    public static function isAuthorizedKnown( int $bit ): bool {
        return  ( $bit & self::AUTHORIZED_KNOWN_BIT ) !== 0;
    }

    public static function isAuthorizedOpen( int $bit ): bool {
        return  ( $bit & self::AUTHORIZED_OPEN_BIT ) !== 0;
    }

    public static function isValidBits( int $bits ):bool {
        return ( 
            ( ( $bits & self::NETFREE_KNOWN_BIT ) !== 0 || ( $bits & self::NETFREE_OPEN_BIT ) === 0 ) && 
            ( ( $bits & self::AUTHORIZED_KNOWN_BIT ) !== 0 || ( $bits & self::AUTHORIZED_OPEN_BIT ) === 0 ) 
        );
    }

}
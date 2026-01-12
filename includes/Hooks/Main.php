<?php

namespace MediaWiki\Extension\AspaklaryaImages\Hooks;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use MediaWiki\Extension\AspaklaryaImages\File as AIFile;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class Main implements ImageBeforeProduceHTMLHook, BeforePageDisplayHook {


    public function __construct( private ILoadBalancer $loadBalancer, private WANObjectCache $cache ) {
        
    }

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$title->canExist() ) {
			return;
		}
		$out->addModuleStyles( 'ext.aspaklaryaimages.styles' );
	}
	
    /**
	 * This hook is called before producing the HTML created by a wiki image insertion.
	 * You can skip the default logic entirely by returning false, or just modify a few
	 * things using call-by-reference.
	 *
	 * @since 1.35
	 *
	 * @param null $unused Will always be null
	 * @param Title &$title Title object of the image
	 * @param File|false &$file File object, or false if it doesn't exist
	 * @param array &$frameParams Various parameters with special meanings; see documentation in
	 *   includes/Linker.php for Linker::makeImageLink
	 * @param array &$handlerParams Various parameters with special meanings; see documentation in
	 *   includes/Linker.php for Linker::makeImageLink
	 * @param string|bool &$time Timestamp of file in 'YYYYMMDDHHIISS' string
	 *   form, or false for current
	 * @param string &$res Final HTML output, used if you return false
	 * @param Parser $parser
	 * @param string &$query Query params for desc URL
	 * @param string &$widthOption Used by the parser to remember the user preference thumbnailsize
	 * @return bool|void True or no return value to continue or false to skip the default logic
	 */
	public function onImageBeforeProduceHTML( $unused, &$title, &$file,
		&$frameParams, &$handlerParams, &$time, &$res, $parser, &$query, &$widthOption
	) {
        $fileClass = new AIFile( $this->loadBalancer, $this->cache, $title->getPrefixedDBKey() );
        $netfreeStatus = $fileClass->getNetfreeStatus();
        $authorizedStatus = $fileClass->getAuthorizedStatus();
        if ( $authorizedStatus === false ) {
            $res = '';
			$parser->addTrackingCategory( 'aspaklarya-images-unauthorized-category' );
            return false;
        }
        if ( !isset( $frameParams['class'] ) ) {
            $frameParams['class'] = '';
        }
        if ( $netfreeStatus === null ) {
			$parser->addTrackingCategory( 'aspaklarya-images-netfree-unknown-category' );
            $frameParams[ 'class' ] .= ' aspaklarya-images-netfree-unknown ';
        } elseif ( !$netfreeStatus ) {
			$parser->addTrackingCategory( 'aspaklarya-images-netfree-blocked-category' );
            $frameParams[ 'class' ] .= ' aspaklarya-images-netfree-blocked ';
        } 
        return true;
    }
}
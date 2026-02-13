<?php

namespace MediaWiki\Extension\AspaklaryaImages\Hooks;

use MediaWiki\Extension\AspaklaryaImages\File;
use MediaWiki\Extension\AspaklaryaImages\NolinesImageGallery;
use MediaWiki\Extension\AspaklaryaImages\PackedHoverImageGallery;
use MediaWiki\Extension\AspaklaryaImages\PackedImageGallery;
use MediaWiki\Extension\AspaklaryaImages\PackedOverlayImageGallery;
use MediaWiki\Extension\AspaklaryaImages\SlideshowImageGallery;
use MediaWiki\Extension\AspaklaryaImages\TraditionalImageGallery;
use MediaWiki\Hook\AfterParserFetchFileAndTitleHook;
use MediaWiki\Hook\GalleryGetModesHook;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class Main implements ImageBeforeProduceHTMLHook, BeforePageDisplayHook, GetPreferencesHook, AfterParserFetchFileAndTitleHook, GalleryGetModesHook {
	private array $availableOptions = [ 'unknown', 'blocked' ];

	public function __construct( private ILoadBalancer $loadBalancer, private WANObjectCache $cache ) {
	}

	/**
	 * @inheritDoc
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$options = [];
		foreach ( $this->availableOptions as $option ) {
			if ( !$user->isAllowed( "aspaklaryaimages-show-$option-images" ) ) {
				continue;
			}
			$options["aspaklaryaimages-show-$option"] = "-show-$option";
		}
		$preferences['aspaklaryaimages'] = [
				'type' => 'multiselect',
				'label-message' => 'aspaklaryaimages-preference-label',
				'options-messages' => $options,
				'help-message' => 'aspaklaryaimages-preference-help',
				'section' => 'aspaklarya/images',
			];
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$title->canExist() ) {
			return;
		}
		$user = $out->getUser();
		$bodyClasses = '';
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		foreach ( $this->availableOptions as $option ) {
			$right = "aspaklaryaimages-show-$option-images";
			$class = " ai-preference-hide-$option";
			$userOption = "aspaklaryaimages-show-$option";
			if ( !$user || !$user->isSafeToLoad() || !$user->isAllowed( $right ) ) {
				if ( !(bool)$userOptionsLookup->getDefaultOption( $userOption ) ) {
					$bodyClasses .= $class;
				}
				continue;
			}
			$optionValue = $userOptionsLookup->getOption( $user, $userOption );
			if ( $optionValue === null ) {
				if ( !(bool)$userOptionsLookup->getDefaultOption( $userOption ) ) {
					$bodyClasses .= $class;
				}
				continue;
			}
			if ( !(bool)$optionValue ) {
				$bodyClasses .= $class;
			}
		}

		$out->addBodyClasses( $bodyClasses );
		$out->addModuleStyles( 'ext.aspaklaryaimages.styles' );
	}

	/**
	 * @inheritDoc
	 */
	public function onImageBeforeProduceHTML( $unused, &$title, &$file,
		&$frameParams, &$handlerParams, &$time, &$res, $parser, &$query, &$widthOption
	) {
		return $this->getImageStatus( $title, $frameParams, $res, $parser );
	}

	/**
	 * @param Parser $parser
	 * @param TraditionalImageGallery $ig
	 * @param string &$html
	 * @return void
	 */
	public function onAfterParserFetchFileAndTitle( $parser, $ig, &$html ) {
		$removed = false;
		$frame = [];
		$res = '';
		foreach ( $ig->getImages() as $index => $image ) {
			if ( !$this->getImageStatus( $image[0], $frame, $res, $parser ) ) {
				$ig->removeImage( $index );
				$removed = true;
			}
		}
		if ( $removed ) {
			$html = $ig->toHTML();
		}
	}

	private function getImageStatus( Title $title, &$frameParams, &$res, Parser $parser ): bool {
		$fileClass = new File( $this->loadBalancer, $this->cache, $title );
		$netfreeStatus = $fileClass->getNetfreeStatus();
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$blockUnknown = (bool)$config->get( 'BlockNetfreeUnknownImages' );
		if ( $blockUnknown && !(bool)$netfreeStatus ) {
			$res = '';
			return false;
		}
		$authorizedStatus = $fileClass->getAuthorizedStatus();
		if ( $authorizedStatus === false ) {
			$res = '';
			$parser->addTrackingCategory( 'aspaklaryaimages-unauthorized-category' );
			return false;
		}
		if ( !isset( $frameParams['class'] ) ) {
			$frameParams['class'] = '';
		}
		if ( $netfreeStatus === null ) {
			$parser->addTrackingCategory( 'aspaklaryaimages-netfree-unknown-category' );
			$frameParams[ 'class' ] .= ' aspaklaryaimages-netfree-unknown ';
		} elseif ( !(bool)$netfreeStatus ) {
			$parser->addTrackingCategory( 'aspaklaryaimages-netfree-blocked-category' );
			$frameParams[ 'class' ] .= ' aspaklaryaimages-netfree-blocked ';
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onGalleryGetModes( &$modes ) {
		$modes = [
			'traditional' => TraditionalImageGallery::class,
			'nolines' => NolinesImageGallery::class,
			'packed' => PackedImageGallery::class,
			'packed-hover' => PackedHoverImageGallery::class,
			'packed-overlay' => PackedOverlayImageGallery::class,
			'slideshow' => SlideshowImageGallery::class,
		];
	}
}

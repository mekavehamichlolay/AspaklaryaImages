<?php

namespace MediaWiki\Extension\AspaklaryaImages\Gallery;

use ImageGalleryBase;
use MediaTransformOutput;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

class NoBrokenImagesGallery extends TraditionalImageGallery {

	public function toHTML() {
		$resolveFilesViaParser = $this->mParser instanceof Parser;
		if ( $resolveFilesViaParser ) {
			$parserOutput = $this->mParser->getOutput();
			$repoGroup = null;
			$linkRenderer = $this->mParser->getLinkRenderer();
			$badFileLookup = $this->mParser->getBadFileLookup();
		} else {
			$parserOutput = $this->getOutput();
			$services = MediaWikiServices::getInstance();
			$repoGroup = $services->getRepoGroup();
			$linkRenderer = $services->getLinkRenderer();
			$badFileLookup = $services->getBadFileLookup();
		}

		Html::addClass( $this->mAttribs['class'], 'gallery' );
		Html::addClass( $this->mAttribs['class'], 'mw-gallery-' . $this->mMode );

		if ( $this->mPerRow > 0 ) {
			$maxwidth = $this->mPerRow * ( $this->mWidths + $this->getAllPadding() );
			$oldStyle = $this->mAttribs['style'] ?? '';
			$this->mAttribs['style'] = "max-width: {$maxwidth}px;" . $oldStyle;
		}

		$parserOutput->addModules( $this->getModules() );
		$parserOutput->addModuleStyles( [ 'mediawiki.page.gallery.styles' ] );
		$output = Html::openElement( 'ul', $this->mAttribs );
		if ( $this->mCaption ) {
			$output .= "\n\t" . Html::rawElement( 'li', [ 'class' => 'gallerycaption' ], $this->mCaption );
		}

		if ( $this->mShowFilename ) {
			// Preload LinkCache info for when generating links
			// of the filename below
			$linkBatchFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
			$lb = $linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
			foreach ( $this->mImages as [ $title, /* see below */ ] ) {
				$lb->addObj( $title );
			}
			$lb->execute();
		}

		$lang = $this->getRenderLang();
		$enableLegacyMediaDOM =
			$this->getConfig()->get( MainConfigNames::ParserEnableLegacyMediaDOM );
		$hookRunner = new HookRunner( MediaWikiServices::getInstance()->getHookContainer() );

		# Output each image...
		foreach ( $this->mImages as [ $nt, $text, $alt, $link, $handlerOpts, $loading, $imageOptions ] ) {
			// "text" means "caption" here
			/** @var Title $nt */

			$descQuery = false;
			if ( $nt->inNamespace( NS_FILE ) && !$nt->isExternal() ) {
				# Get the file...
				if ( $resolveFilesViaParser ) {
					# Give extensions a chance to select the file revision for us
					$options = [];
					$hookRunner->onBeforeParserFetchFileAndTitle(
						// @phan-suppress-next-line PhanTypeMismatchArgument Type mismatch on pass-by-ref args
						$this->mParser, $nt, $options, $descQuery );
					# Fetch and register the file (file title may be different via hooks)
					[ $img, $nt ] = $this->mParser->fetchFileAndTitle( $nt, $options );
				} else {
					$img = $repoGroup->findFile( $nt );
				}
			} else {
				$img = false;
			}

			$transformOptions = $this->getThumbParams( $img ) + $handlerOpts;
			$thumb = $img ? $img->transform( $transformOptions ) : false;

			$rdfaType = 'mw:File';

			$isBadFile = $img && $thumb && $this->mHideBadImages &&
				$badFileLookup->isBadFile( $nt->getDBkey(), $this->getContextTitle() );

			if ( !$img || !$thumb || ( !$enableLegacyMediaDOM && $thumb->isError() ) || $isBadFile ) {
				continue;
			} else {
				/** @var MediaTransformOutput $thumb */
				$vpad = $this->getVPad( $this->mHeights, $thumb->getHeight() );

				// Backwards compat before the $imageOptions existed
				if ( $imageOptions === null ) {
					$imageParameters = [
						'desc-link' => true,
						'desc-query' => $descQuery,
						'alt' => $alt ?? '',
						'custom-url-link' => $link
					];
				} else {
					$params = [];
					// An empty alt indicates an image is not a key part of the
					// content and that non-visual browsers may omit it from
					// rendering.  Only set the parameter if it's explicitly
					// requested.
					if ( $alt !== null ) {
						$params['alt'] = $alt;
					}
					$params['title'] = $imageOptions['title'];
					if ( !$enableLegacyMediaDOM ) {
						$params['img-class'] = 'mw-file-element';
					}
					$imageParameters = Linker::getImageLinkMTOParams(
						$imageOptions, $descQuery, $this->mParser
					) + $params;
				}

				if ( $loading === ImageGalleryBase::LOADING_LAZY ) {
					$imageParameters['loading'] = 'lazy';
				}

				$this->adjustImageParameters( $thumb, $imageParameters );

				Linker::processResponsiveImages( $img, $thumb, $transformOptions );

				$thumbhtml = $thumb->toHtml( $imageParameters );

				if ( !$enableLegacyMediaDOM ) {
					$thumbhtml = Html::rawElement(
						'span', [ 'typeof' => $rdfaType ], $thumbhtml
					);
				} else {
					$thumbhtml = Html::rawElement( 'div', [
						# Auto-margin centering for block-level elements. Needed
						# now that we have video handlers since they may emit block-
						# level elements as opposed to simple <img> tags. ref
						# http://css-discuss.incutio.com/?page=CenteringBlockElement
						'style' => "margin:{$vpad}px auto;",
					], $thumbhtml );
				}

				# Set both fixed width and min-height.
				$width = $this->getThumbDivWidth( $thumb->getWidth() );
				$height = $this->getThumbPadding() + $this->mHeights;
				$thumbhtml = "\n\t\t\t" . Html::rawElement( 'div', [
					'class' => 'thumb',
					'style' => "width: {$width}px;" .
						( !$enableLegacyMediaDOM && $this->mMode === 'traditional' ?
							" height: {$height}px;" : '' ),
				], $thumbhtml );

				// Call parser transform hook
				if ( $resolveFilesViaParser ) {
					/** @var MediaHandler $handler */
					$handler = $img->getHandler();
					if ( $handler ) {
						$handler->parserTransformHook( $this->mParser, $img );
					}
					$this->mParser->modifyImageHtml(
						$img, [ 'handler' => $imageParameters ], $thumbhtml );
				}
			}

			$galleryText = $this->wrapGalleryText( $text, $thumb );

			$gbWidth = $this->getGBWidthOverwrite( $thumb ) ?: $this->getGBWidth( $thumb ) . 'px';
			# Weird double wrapping (the extra div inside the li) needed due to FF2 bug
			# Can be safely removed if FF2 falls completely out of existence
			$output .= "\n\t\t" .
			Html::rawElement(
				'li',
				[ 'class' => 'gallerybox', 'style' => 'width: ' . $gbWidth ],
				( $enableLegacyMediaDOM ? Html::openElement( 'div', [ 'style' => 'width: ' . $gbWidth ] ) : '' )
					. $thumbhtml
					. $galleryText
					. "\n\t\t"
					. ( $enableLegacyMediaDOM ? Html::closeElement( 'div' ) : '' )
			);
		}
		$output .= "\n" . Html::closeElement( 'ul' );

		return $output;
	}

}

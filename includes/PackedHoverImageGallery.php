<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedHoverImageGallery as GlobalPackedHoverImageGallery;

class PackedHoverImageGallery extends GlobalPackedHoverImageGallery {
	public function removeImage( $index ) {
		$this->mImages = array_splice( $this->mImages, $index, 1 );
	}
}

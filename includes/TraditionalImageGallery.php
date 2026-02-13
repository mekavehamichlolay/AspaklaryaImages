<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use TraditionalImageGallery as GlobalTraditionalImageGallery;

class TraditionalImageGallery extends GlobalTraditionalImageGallery {

	public function removeImage( $index ) {
		$this->mImages = array_splice( $this->mImages, $index, 1 );
	}

}

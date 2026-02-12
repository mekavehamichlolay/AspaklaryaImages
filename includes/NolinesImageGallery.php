<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use NolinesImageGallery as GlobalNolinesImageGallery;

class NolinesImageGallery extends GlobalNolinesImageGallery {
	public function removeImage( $index ) {
		$this->mImages = array_splice( $this->mImages, $index, 1 );
	}
}

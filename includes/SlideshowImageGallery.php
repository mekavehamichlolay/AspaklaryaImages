<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use SlideshowImageGallery as GlobalSlideshowImageGallery;

class SlideshowImageGallery extends GlobalSlideshowImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

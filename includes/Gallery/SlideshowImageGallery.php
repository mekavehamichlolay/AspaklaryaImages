<?php

namespace MediaWiki\Extension\AspaklaryaImages\Gallery;

use SlideshowImageGallery as GlobalSlideshowImageGallery;

class SlideshowImageGallery extends GlobalSlideshowImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

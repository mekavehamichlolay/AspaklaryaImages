<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use NolinesImageGallery as GlobalNolinesImageGallery;

class NolinesImageGallery extends GlobalNolinesImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use TraditionalImageGallery as GlobalTraditionalImageGallery;

class TraditionalImageGallery extends GlobalTraditionalImageGallery {

	public function setImages ( $images ) {
		$this->mImages = $images;
	}

}

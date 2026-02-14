<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedHoverImageGallery as GlobalPackedHoverImageGallery;

class PackedHoverImageGallery extends GlobalPackedHoverImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

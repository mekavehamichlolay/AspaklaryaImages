<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedImageGallery as GlobalPackedImageGallery;

class PackedImageGallery extends GlobalPackedImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

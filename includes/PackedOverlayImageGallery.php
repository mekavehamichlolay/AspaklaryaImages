<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedOverlayImageGallery as GlobalPackedOverlayImageGallery;

class PackedOverlayImageGallery extends GlobalPackedOverlayImageGallery {
	public function setImages ( $images ) {
		$this->mImages = $images;
	}
}

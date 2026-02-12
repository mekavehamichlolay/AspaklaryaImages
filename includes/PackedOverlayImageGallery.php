<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedOverlayImageGallery as GlobalPackedOverlayImageGallery;

class PackedOverlayImageGallery extends GlobalPackedOverlayImageGallery {
    public function removeImage( $index ) {
        array_splice( $this->mImages, $index, 1 );
    }
}
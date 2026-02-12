<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedImageGallery as GlobalPackedImageGallery;

class PackedImageGallery extends GlobalPackedImageGallery {
    public function removeImage( $index ) {
        array_splice( $this->mImages, $index, 1 );
    }
}
<?php

use PHPUnit\Framework\TestCase;

class WPS3FKeyBuilderTest extends TestCase {
    public function test_build_key_keeps_upload_layout() {
        $key = WPS3F_Key_Builder::build_key('wp-content/uploads', '2026/04/example.png');

        $this->assertSame('wp-content/uploads/2026/04/example.png', $key);
    }

    public function test_build_key_normalizes_slashes() {
        $key = WPS3F_Key_Builder::build_key('/wp-content//uploads/', '\\2026\\04\\example.png');

        $this->assertSame('wp-content/uploads/2026/04/example.png', $key);
    }

    public function test_encode_path_encodes_each_segment() {
        $encoded = WPS3F_Key_Builder::encode_path('wp content/uploads/文件.png');

        $this->assertSame('wp%20content/uploads/%E6%96%87%E4%BB%B6.png', $encoded);
    }
}

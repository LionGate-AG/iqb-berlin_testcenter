<?php

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FileExtTest extends TestCase {
  function test_get() {
    $this->assertEquals('ddd', FileExt::get('/xxx/yyy/aaa.bbb.ccc.ddd'));
    $this->assertEquals('ccc.ddd', FileExt::get('/xxx/yyy/aaa.bbb.ccc.ddd', 1));
    $this->assertEquals('bbb.ccc.ddd', FileExt::get('/xxx/yyy/aaa.bbb.ccc.ddd', 2));
    $this->assertEquals('', FileExt::get('one.dot', 2));
    $this->assertEquals('dot', FileExt::get('one.dot', 0));
    $this->assertEquals('', FileExt::get('nodotatall', 2));
    $this->assertEquals('', FileExt::get('nodotatall', 0));
  }

  function test_has() {
    $this->assertTrue(FileExt::has('/xxx/yyy/aaa.bbb.ccc.ddd', 'ccc.ddd'));
    $this->assertTrue(FileExt::has('/xxx/yyy/aaa.bbb.ccc.ddd', 'ddd'));
    $this->assertTrue(FileExt::has('/xxx/yyy/aaa.bbb.ccc.ddD', 'dDd'));
    $this->assertFalse(FileExt::has('/xxx/yyy/aaa.bbb.ccc.ddd', 'aaa.bbb.ccc.ddd'));
  }

  function test_getMimeType_byExtensionAlsoForFilesNotOnDisk() {
    $this->assertEquals('application/pdf', FileExt::getMimeType('/not/on/disk/attachment.PDF'));
    $this->assertEquals('image/png', FileExt::getMimeType('ws_1/UnitAttachments/scan.png'));
    $this->assertEquals('application/octet-stream', FileExt::getMimeType('/not/on/disk/unknown.xyz'));
  }

}


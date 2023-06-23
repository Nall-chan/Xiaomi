<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class LibraryTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateCloudIO(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Cloud IO');
    }
    public function testValidateConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Configurator');
    }
    public function testValidateMiDevice(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Mi Device');
    }
    public function testValidateAqaraSplitter(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Aqara Splitter');
    }
    public function testValidateAqaraConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Aqara Configurator');
    }
    public function testValidateAqaraDevice(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi Aqara Device');
    }
}

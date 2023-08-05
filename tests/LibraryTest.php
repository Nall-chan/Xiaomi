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
        $this->validateModule(__DIR__ . '/../Xiaomi MIoT Cloud IO');
    }
    public function testValidateConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi MIoT Configurator');
    }
    public function testValidateMiDevice(): void
    {
        $this->validateModule(__DIR__ . '/../Xiaomi MIoT Device');
    }
}

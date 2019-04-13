<?php

declare(strict_types=1);

namespace Doctrine\Tests\DBAL\Types;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\Tests\DbalTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use function array_map;
use function base64_encode;
use function fopen;
use function implode;
use function range;

class BinaryTest extends DbalTestCase
{
    /** @var AbstractPlatform|MockObject */
    protected $platform;

    /** @var BinaryType */
    protected $type;

    /**
     * {@inheritdoc}
     */
    protected function setUp() : void
    {
        $this->platform = $this->createMock(AbstractPlatform::class);
        $this->type     = Type::getType('binary');
    }

    public function testReturnsBindingType() : void
    {
        self::assertSame(ParameterType::BINARY, $this->type->getBindingType());
    }

    public function testReturnsName() : void
    {
        self::assertSame(Type::BINARY, $this->type->getName());
    }

    public function testReturnsSQLDeclaration() : void
    {
        $this->platform->expects($this->once())
            ->method('getBinaryTypeDeclarationSQL')
            ->willReturn('TEST_BINARY');

        self::assertSame('TEST_BINARY', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testBinaryNullConvertsToPHPValue() : void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testBinaryStringConvertsToPHPValue() : void
    {
        $databaseValue = $this->getBinaryString();
        $phpValue      = $this->type->convertToPHPValue($databaseValue, $this->platform);

        self::assertSame($databaseValue, $phpValue);
    }

    public function testBinaryResourceConvertsToPHPValue() : void
    {
        $databaseValue = fopen('data://text/plain;base64,' . base64_encode('binary string'), 'r');
        $phpValue      = $this->type->convertToPHPValue($databaseValue, $this->platform);

        self::assertSame('binary string', $phpValue);
    }

    /**
     * Creates a binary string containing all possible byte values.
     */
    private function getBinaryString() : string
    {
        return implode(array_map('chr', range(0, 255)));
    }

    /**
     * @param mixed $value
     *
     * @dataProvider getInvalidDatabaseValues
     */
    public function testThrowsConversionExceptionOnInvalidDatabaseValue($value) : void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue($value, $this->platform);
    }

    /**
     * @return mixed[][]
     */
    public static function getInvalidDatabaseValues() : iterable
    {
        return [
            [false],
            [true],
            [0],
            [1],
            [-1],
            [0.0],
            [1.1],
            [-1.1],
        ];
    }
}

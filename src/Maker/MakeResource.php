<?php

declare(strict_types=1);

namespace GeekCell\DddBundle\Maker;

use Assert\Assert;
use GeekCell\Ddd\Contracts\Application\CommandBus;
use GeekCell\Ddd\Contracts\Application\QueryBus;
use GeekCell\DddBundle\Maker\ApiPlatform\ApiPlatformConfigUpdater;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputAwareMakerInterface;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Util\ClassNameDetails;
use Symfony\Bundle\MakerBundle\Util\UseStatementGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class MakeResource extends AbstractMaker implements InputAwareMakerInterface
{
    public const NAMESPACE_PREFIX = 'Infrastructure\\ApiPlatform\\';
    public const CONFIG_PATH = 'config/packages/api_platform.yaml';
    public const CONFIG_PATH_XML = 'Infrastructure/ApiPlatform/Config';

    /**
     * @see \ApiPlatform\Metadata\ApiResource
     */
    private const API_PLATFORM_RESOURCE_CLASS = 'ApiPlatform\Metadata\ApiResource';

    /**
     * @see \ApiPlatform\Metadata\ApiProperty
     */
    private const API_PLATFORM_PROPERTY_CLASS = 'ApiPlatform\Metadata\ApiProperty';

    /**
     * @see \ApiPlatform\Symfony\Bundle\ApiPlatformBundle
     */
    private const API_PLATFORM_BUNDLE_CLASS = 'ApiPlatform\Symfony\Bundle\ApiPlatformBundle';

    /**
     * @see \ApiPlatform\Metadata\Operation
     */
    private const API_PLATFORM_OPERATION_CLASS = 'ApiPlatform\Metadata\Operation';

    /**
     * @see \ApiPlatform\State\ProcessorInterface
     */
    private const API_PLATFORM_PROCESSOR_INTERFACE = 'ApiPlatform\State\ProcessorInterface';

    /**
     * @see \ApiPlatform\State\ProviderInterface
     */
    private const API_PLATFORM_PROVIDER_INTERFACE = 'ApiPlatform\State\ProviderInterface';

    public const CONFIG_FLAVOR_ATTRIBUTE = 'attribute';
    public const CONFIG_FLAVOR_XML = 'xml';

    public function __construct(
        private FileManager $fileManager,
        private ApiPlatformConfigUpdater $configUpdater
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getCommandName(): string
    {
        return 'make:ddd:resource';
    }

    /**
     * @inheritDoc
     */
    public static function getCommandDescription(): string
    {
        return 'Creates a new API Platform resource';
    }

    /**
     * @inheritDoc
     */
    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the model class to create the resource for (e.g. <fg=yellow>Customer</>). Model must exist already.',
            )
            ->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Config flavor to create (attribute|xml).',
                null
            )
            ->addOption(
                'base-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Base path from which to generate model & config.',
                null
            )
        ;
    }

    /**
     * @inheritDoc
     */
    public function configureDependencies(DependencyBuilder $dependencies, InputInterface $input = null): void
    {
    }

    /**
     * @inheritDoc
     */
    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        // Check for bundle to make sure API Platform package is installed.
        // Then check if the new ApiResource class in the Metadata namespace exists.
        //  -> Was only introduced in v2.7.
        if (!class_exists(self::API_PLATFORM_BUNDLE_CLASS) || !class_exists(self::API_PLATFORM_RESOURCE_CLASS)) {
            throw new RuntimeCommandException('This command requires Api Platform >2.7 to be installed.');
        }

        if (false === $input->getOption('config')) {
            $configFlavor = $io->choice(
                'Config flavor to create (attribute|xml). (<fg=yellow>%sModel</>)',
                [
                    self::CONFIG_FLAVOR_ATTRIBUTE => 'PHP attributes',
                    self::CONFIG_PATH_XML => 'XML mapping',
                ],
                self::CONFIG_FLAVOR_ATTRIBUTE
            );
            $input->setOption('config', $configFlavor);
        }

        if (null === $input->getOption('base-path')) {
            $basePath = $io->ask(
                'Which base path should be used? Default is "' . PathGenerator::DEFAULT_BASE_PATH . '"',
                PathGenerator::DEFAULT_BASE_PATH,
            );
            $input->setOption('base-path', $basePath);
        }
    }

    /**
     * @inheritDoc
     */
    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $baseName = $input->getArgument('name');
        Assert::that($baseName)->string();
        $configFlavor = $input->getOption('config');
        Assert::that($configFlavor)->string();
        $basePath = $input->getOption('base-path');
        Assert::that($basePath)->string();
        $pathGenerator = new PathGenerator($basePath);

        if (!in_array($configFlavor, [self::CONFIG_FLAVOR_ATTRIBUTE, self::CONFIG_FLAVOR_XML], true)) {
            throw new RuntimeCommandException('Unknown config flavor: ' . $configFlavor);
        }

        $modelClassNameDetails = $generator->createClassNameDetails(
            $baseName,
            $pathGenerator->namespacePrefix('Domain\\Model\\'),
        );

        if (!class_exists($modelClassNameDetails->getFullName())) {
            throw new RuntimeCommandException("Could not find model {$modelClassNameDetails->getFullName()}!");
        }

        $identityClassNameDetails = $this->ensureIdentity($modelClassNameDetails, $generator, $pathGenerator);

        $classNameDetails = $generator->createClassNameDetails(
            $baseName,
            $pathGenerator->namespacePrefix(self::NAMESPACE_PREFIX . 'Resource'),
            'Resource',
        );

        $this->ensureConfig($generator, $pathGenerator, $configFlavor);

        $providerClassNameDetails = $generator->createClassNameDetails(
            $baseName,
            $pathGenerator->namespacePrefix(self::NAMESPACE_PREFIX . 'State'),
            'Provider',
        );
        $this->generateProvider($providerClassNameDetails, $generator);

        $processorClassNameDetails = $generator->createClassNameDetails(
            $baseName,
            $pathGenerator->namespacePrefix(self::NAMESPACE_PREFIX . 'State'),
            'Processor',
        );
        $this->generateProcessor($processorClassNameDetails, $generator);

        $classesToImport = [$modelClassNameDetails->getFullName()];
        if ($configFlavor === self::CONFIG_FLAVOR_ATTRIBUTE) {
            $classesToImport[] = self::API_PLATFORM_RESOURCE_CLASS;
            $classesToImport[] = self::API_PLATFORM_PROPERTY_CLASS;
            $classesToImport[] = $providerClassNameDetails->getFullName();
            $classesToImport[] = $processorClassNameDetails->getFullName();
        }

        $configureWithUuid = str_contains(strtolower($identityClassNameDetails->getShortName()), 'uuid');
        $templateVars = [
            'use_statements' => new UseStatementGenerator($classesToImport),
            'entity_class_name' => $modelClassNameDetails->getShortName(),
            'provider_class_name' => $providerClassNameDetails->getShortName(),
            'processor_class_name' => $processorClassNameDetails->getShortName(),
            'configure_with_attributes' => $configFlavor === self::CONFIG_FLAVOR_ATTRIBUTE,
            'configure_with_uuid' => $configureWithUuid,
        ];

        $generator->generateClass(
            $classNameDetails->getFullName(),
            __DIR__.'/../Resources/skeleton/resource/Resource.tpl.php',
            $templateVars,
        );

        if ($configFlavor === self::CONFIG_FLAVOR_XML) {
            $targetPathResourceConfig = $pathGenerator->path('src/', self::CONFIG_PATH_XML . '/' . $classNameDetails->getShortName() . '.xml');
            $generator->generateFile(
                $targetPathResourceConfig,
                __DIR__.'/../Resources/skeleton/resource/ResourceXmlConfig.tpl.php',
                [
                    'class_name' => $classNameDetails->getFullName(),
                    'entity_short_class_name' => $modelClassNameDetails->getShortName(),
                    'provider_class_name' => $providerClassNameDetails->getFullName(),
                    'processor_class_name' => $processorClassNameDetails->getFullName(),
                ]
            );

            $targetPathPropertiesConfig = $pathGenerator->path('src/', self::CONFIG_PATH_XML . '/' . $classNameDetails->getShortName() . 'Properties.xml');
            $generator->generateFile(
                $targetPathPropertiesConfig,
                __DIR__.'/../Resources/skeleton/resource/PropertiesXmlConfig.tpl.php',
                [
                    'class_name' => $classNameDetails->getFullName(),
                    'identifier_field_name' => $configureWithUuid ? 'uuid' : 'id',
                ]
            );
        }

        $generator->writeChanges();

        $this->writeSuccessMessage($io);
    }

    /**
     * ensure custom resource path(s) are added to config
     *
     * @param Generator $generator
     * @param PathGenerator $pathGenerator
     * @param string $configFlavor
     * @return void
     */
    private function ensureConfig(Generator $generator, PathGenerator $pathGenerator, string $configFlavor): void
    {
        $customResourcePath = $pathGenerator->path('%kernel.project_dir%/src', 'Infrastructure/ApiPlatform/Resource');
        $customConfigPath = $pathGenerator->path('%kernel.project_dir%/src', self::CONFIG_PATH_XML);

        if (!$this->fileManager->fileExists(self::CONFIG_PATH)) {
            $generator->generateFile(
                self::CONFIG_PATH,
                __DIR__ . '/../Resources/skeleton/resource/ApiPlatformConfig.tpl.php',
                [
                    'path' => $customResourcePath,
                ]
            );

            $generator->writeChanges();
        }

        $newYaml = $this->configUpdater->addCustomPath(
            $this->fileManager->getFileContents(self::CONFIG_PATH),
            $customResourcePath
        );

        if ($configFlavor === self::CONFIG_FLAVOR_XML) {
            $newYaml = $this->configUpdater->addCustomPath($newYaml, $customConfigPath);
        }

        $generator->dumpFile(self::CONFIG_PATH, $newYaml);

        $generator->writeChanges();
    }

    /**
     * @param ClassNameDetails $providerClassNameDetails
     * @param Generator $generator
     * @return void
     * @throws \Exception
     */
    private function generateProvider(ClassNameDetails $providerClassNameDetails, Generator $generator): void
    {
        $templateVars = [
            'use_statements' => new UseStatementGenerator([
                self::API_PLATFORM_PROVIDER_INTERFACE,
                QueryBus::class,
                self::API_PLATFORM_OPERATION_CLASS
            ]),
        ];

        $generator->generateClass(
            $providerClassNameDetails->getFullName(),
            __DIR__.'/../Resources/skeleton/resource/Provider.tpl.php',
            $templateVars,
        );

        $generator->writeChanges();
    }

    /**
     * @param ClassNameDetails $processorClassNameDetails
     * @param Generator $generator
     * @return void
     * @throws \Exception
     */
    private function generateProcessor(ClassNameDetails $processorClassNameDetails, Generator $generator): void
    {
        $templateVars = [
            'use_statements' => new UseStatementGenerator([
                self::API_PLATFORM_PROCESSOR_INTERFACE,
                CommandBus::class,
                self::API_PLATFORM_OPERATION_CLASS
            ]),
        ];

        $generator->generateClass(
            $processorClassNameDetails->getFullName(),
            __DIR__.'/../Resources/skeleton/resource/Processor.tpl.php',
            $templateVars,
        );

        $generator->writeChanges();
    }

    /**
     * @param ClassNameDetails $modelClassNameDetails
     * @param Generator $generator
     * @param PathGenerator $pathGenerator
     * @return ClassNameDetails
     */
    private function ensureIdentity(ClassNameDetails $modelClassNameDetails, Generator $generator, PathGenerator $pathGenerator): ClassNameDetails
    {
        $idEntity = $generator->createClassNameDetails(
            $modelClassNameDetails->getShortName(),
            $pathGenerator->namespacePrefix('Domain\\Model\\ValueObject\\Identity'),
            'Id',
        );

        if (class_exists($idEntity->getFullName())) {
            return $idEntity;
        }

        $uuidEntity = $generator->createClassNameDetails(
            $modelClassNameDetails->getShortName(),
            $pathGenerator->namespacePrefix('Domain\\Model\\ValueObject\\Identity'),
            'Uuid',
        );

        if (class_exists($uuidEntity->getFullName())) {
            return $uuidEntity;
        }

        throw new RuntimeCommandException("Could not find model identity for {$modelClassNameDetails->getFullName()}. Checked for id class ({$idEntity->getFullName()}) and uuid class ({$uuidEntity->getFullName()})!");
    }
}

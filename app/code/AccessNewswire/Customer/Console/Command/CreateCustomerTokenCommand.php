<?php
declare(strict_types=1);

namespace AccessNewswire\Customer\Console\Command;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParametersFactory;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Proof-of-concept: issues a customer bearer token (as used in GraphQL's
 * `Authorization: Bearer` header) without the customer's password - the
 * same way Login as Customer does.
 */
class CreateCustomerTokenCommand extends Command
{
    private const ARGUMENT_EMAIL = 'email';
    private const OPTION_WEBSITE = 'website';
    private const OPTION_CREATE = 'create';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly UserTokenIssuerInterface $tokenIssuer,
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly UserTokenParametersFactory $tokenParametersFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('accessnewswire:customer:token')
            ->setDescription('Issue a customer API token for an email address (proof of concept)')
            ->addArgument(self::ARGUMENT_EMAIL, InputArgument::REQUIRED, 'Customer email')
            ->addOption(
                self::OPTION_WEBSITE,
                null,
                InputOption::VALUE_REQUIRED,
                // Customer accounts are per website here (customer/account_share/scope = 1).
                'Website code the customer belongs to',
                'anw'
            )
            ->addOption(self::OPTION_CREATE, null, InputOption::VALUE_NONE, 'Create the customer if missing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument(self::ARGUMENT_EMAIL);
        // Only the token goes to stdout, so it can be captured or piped.
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $website = $this->storeManager->getWebsite((string) $input->getOption(self::OPTION_WEBSITE));
            try {
                $customer = $this->customerRepository->get($email, (int) $website->getId());
            } catch (NoSuchEntityException $e) {
                if (!$input->getOption(self::OPTION_CREATE)) {
                    $stderr->writeln(sprintf(
                        '<error>No customer %s on website "%s". Pass --create to create one.</error>',
                        $email,
                        $website->getCode()
                    ));
                    return Cli::RETURN_FAILURE;
                }
                $customer = $this->customerRepository->save(
                    $this->customerFactory->create()
                        ->setEmail($email)
                        ->setFirstname(strtok($email, '@'))
                        ->setLastname('Customer')
                        ->setWebsiteId((int) $website->getId())
                        ->setStoreId((int) $website->getDefaultStore()->getId())
                );
                $stderr->writeln(sprintf('<info>Created customer #%d.</info>', $customer->getId()));
            }

            $token = $this->tokenIssuer->create(
                new CustomUserContext((int) $customer->getId(), UserContextInterface::USER_TYPE_CUSTOMER),
                $this->tokenParametersFactory->create()
            );
        } catch (LocalizedException $e) {
            $stderr->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln($token);
        $expires = $this->tokenReader->read($token)->getData()->getExpires();
        $stderr->writeln(sprintf(
            '<comment>Customer #%d (%s), expires %s UTC</comment>',
            $customer->getId(),
            $website->getCode(),
            $expires->format('Y-m-d H:i:s')
        ));

        return Cli::RETURN_SUCCESS;
    }
}

<?php

/***
 *
 * This file is part of an "+Pluswerk AG" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2018 Sinian Zhang <sinian.zhang@pluswerk.ag>, +Pluswerk AG
 *
 ***/

declare(strict_types=1);

namespace Pluswerk\MailLogger\Domain\Repository;

use DateTime;
use Exception;
use InvalidArgumentException;
use Pluswerk\MailLogger\Domain\Model\MailLog;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 */
class MailLogRepository extends Repository
{
    protected $defaultOrderings = [
        'crdate' => QueryInterface::ORDER_DESCENDING,
    ];

    /**
     * @var string
     */
    protected $defaultLifetime = '30 days';

    /**
     * @var string
     */
    protected $defaultAnonymizeAfter = '7 days';

    /**
     * @var string
     */
    protected $lifetime = '30 days';

    /**
     * @var string
     */
    protected $anonymizeAfter;

    /**
     * @var string
     */
    protected $anonymizeSymbol = '***';

    /**
     * @var bool
     */
    protected $anonymize = true;

    /**
     * @return void
     */
    public function initializeObject(): void
    {
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = $this->objectManager->get(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);

        // mail logger typoscript settings
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $fullSettings = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
        $settings = $fullSettings['module.']['tx_maillogger.']['settings.'];

        $this->lifetime = $this->defaultLifetime;
        if (isset($settings['cleanup.']['lifetime'])) {
            $this->lifetime = $settings['cleanup.']['lifetime'];
        }
        $this->anonymizeAfter = $this->defaultAnonymizeAfter;
        if (isset($settings['cleanup.']['anonymizeAfter'])) {
            $this->anonymizeAfter = $settings['cleanup.']['anonymizeAfter'];
        }
        if (isset($settings['cleanup.']['anonymize'])) {
            $this->anonymize = (bool)$settings['cleanup.']['anonymize'];
        }

        // cleanup
        $this->cleanupDatabase();

        // anonymize
        $this->anonymizeAll();
    }

    /**
     * Delete old mail log entries (default: 30 days and hard deletion)
     */
    protected function cleanupDatabase(): void
    {
        if ($this->lifetime !== '') {
            $deletionTimestamp = strtotime('-' . $this->lifetime);
            if ($deletionTimestamp === false) {
                throw new Exception(sprintf('Given lifetime string in TypoScript is wrong. lifetime: "%s"', $this->lifetime));
            }

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_maillogger_domain_model_maillog');
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->delete('tx_maillogger_domain_model_maillog')
                ->where($queryBuilder->expr()->lte('crdate', $queryBuilder->createNamedParameter($deletionTimestamp)))
                ->execute();
        }
    }

    /**
     * Anonymize mail logs (default: after 7 days)
     */
    protected function anonymizeAll(): void
    {
        if ($this->anonymize) {
            $timestamp = strtotime('-' . $this->anonymizeAfter);
            if ($timestamp === false) {
                throw new Exception(sprintf('Given lifetime string in TypoScript is wrong. anonymize: "%s"', $this->anonymizeAfter));
            }

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_maillogger_domain_model_maillog');
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->update('tx_maillogger_domain_model_maillog')
                ->set('tstamp', time())
                ->set('subject', $this->anonymizeSymbol)
                ->set('message', $this->anonymizeSymbol)
                ->set('mail_from', $this->anonymizeSymbol)
                ->set('mail_to', $this->anonymizeSymbol)
                ->set('mail_copy', $this->anonymizeSymbol)
                ->set('mail_blind_copy', $this->anonymizeSymbol)
                ->set('headers', $this->anonymizeSymbol)
                ->where($queryBuilder->expr()->lte('crdate', $queryBuilder->createNamedParameter($timestamp)))
                ->execute();
        }
    }

    /**
     * @param MailLog $mailLog
     * @return void
     */
    public function add($mailLog): void
    {
        assert($mailLog instanceof MailLog);
        if (!$mailLog->getCrdate()) {
            $mailLog->_setProperty('crdate', time());
        }
        if (!$mailLog->getTstamp()) {
            $mailLog->_setProperty('tstamp', time());
        }
        $this->anonymizeMailLogIfNeeded($mailLog);
        parent::add($mailLog);
    }

    /**
     * @param MailLog $mailLog
     * @return void
     */
    public function update($mailLog): void
    {
        assert($mailLog instanceof MailLog);
        if ($mailLog->getTstamp() === null) {
            $mailLog->_setProperty('tstamp', time());
        }
        $this->anonymizeMailLogIfNeeded($mailLog);
        parent::update($mailLog);
    }

    protected function anonymizeMailLogIfNeeded(MailLog $mailLog): void
    {
        if ($mailLog->getCrdate() === null) {
            throw new InvalidArgumentException('MailLog must have a crdate');
        }
        if ($this->anonymize === false) {
            return;
        }
        if ($mailLog->getCrdate() > date_modify(new DateTime(), '-' . $this->anonymizeAfter)->getTimestamp()) {
            return;
        }

        $mailLog->setSubject($this->anonymizeSymbol);
        $mailLog->setMessage($this->anonymizeSymbol);
        $mailLog->setMailFrom($this->anonymizeSymbol);
        $mailLog->setMailTo($this->anonymizeSymbol);
        $mailLog->setMailCopy($this->anonymizeSymbol);
        $mailLog->setMailBlindCopy($this->anonymizeSymbol);
        $mailLog->setHeaders($this->anonymizeSymbol);
    }

    public function getLifetime(): string
    {
        return $this->lifetime;
    }

    public function shouldAnonymize(): bool
    {
        return $this->anonymize;
    }

    public function getAnonymizeSymbol(): string
    {
        return $this->anonymizeSymbol;
    }

    public function getAnonymizeAfter(): string
    {
        return $this->anonymizeAfter;
    }
}

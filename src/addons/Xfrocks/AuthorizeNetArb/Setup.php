<?php

namespace Xfrocks\AuthorizeNetArb;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Entity\PaymentProvider;
use XF\Mvc\Entity\Finder;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        /** @var Finder $finder */
        $finder = $this->app->finder('XF:PaymentProvider');
        $providers = $finder->where('addon_id', $this->addOn->getAddOnId())->fetch()->toArray();
        if (count($providers) > 0) {
            return;
        }

        /** @var PaymentProvider $provider */
        $provider = $this->app->em()->create('XF:PaymentProvider');
        $provider->bulkSet([
            'provider_id' => 'authorizenet',
            'provider_class' => 'Xfrocks\\AuthorizeNetArb:Provider',
            'addon_id' => $this->addOn->getAddOnId(),
        ]);

        $provider->save();
    }

    /**
     * Migrate the legacy global \XF::config('enableLivePayments') flag into a
     * per-payment-profile "environment" option. Each existing Authorize.Net
     * profile inherits whatever the global flag currently resolves to, so live
     * sites stay live and test sites stay in the sandbox after the upgrade.
     */
    public function upgrade1050500Step1()
    {
        $environment = \XF::config('enableLivePayments') ? 'production' : 'sandbox';

        /** @var Finder $finder */
        $finder = $this->app->finder('XF:PaymentProfile');
        $profiles = $finder->where('provider_id', 'authorizenet')->fetch();

        foreach ($profiles as $profile) {
            $options = $profile->options;
            if (isset($options['environment'])) {
                continue;
            }

            $options['environment'] = $environment;
            $profile->options = $options;
            $profile->save();
        }
    }
}

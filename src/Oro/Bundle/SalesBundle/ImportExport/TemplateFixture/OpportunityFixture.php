<?php

namespace Oro\Bundle\SalesBundle\ImportExport\TemplateFixture;

use Oro\Bundle\CurrencyBundle\Entity\MultiCurrency;
use Oro\Bundle\ImportExportBundle\TemplateFixture\AbstractTemplateRepository;
use Oro\Bundle\ImportExportBundle\TemplateFixture\TemplateFixtureInterface;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SalesBundle\Entity\Opportunity;

class OpportunityFixture extends AbstractTemplateRepository implements TemplateFixtureInterface
{
    /**
     * {@inheritdoc}
     */
    public function getEntityClass()
    {
        return 'Oro\Bundle\SalesBundle\Entity\Opportunity';
    }

    /**
     * {@inheritdoc}
     */
    public function getData()
    {
        return $this->getEntityData('Jerry Coleman');
    }

    /**
     * {@inheritdoc}
     */
    protected function createEntity($key)
    {
        return new Opportunity();
    }

    /**
     * @param string      $key
     * @param Opportunity $entity
     */
    public function fillEntityData($key, $entity)
    {
        $userRepo         = $this->templateManager->getEntityRepository('Oro\Bundle\UserBundle\Entity\User');
        $customerRepo     = $this->templateManager->getEntityRepository('Oro\Bundle\SalesBundle\Entity\B2bCustomer');
        $contactRepo      = $this->templateManager->getEntityRepository('Oro\Bundle\ContactBundle\Entity\Contact');
        $leadRepo         = $this->templateManager->getEntityRepository('Oro\Bundle\SalesBundle\Entity\Lead');
        $channelRepo      = $this->templateManager->getEntityRepository('Oro\Bundle\ChannelBundle\Entity\Channel');
        $organizationRepo = $this->templateManager
            ->getEntityRepository('Oro\Bundle\OrganizationBundle\Entity\Organization');

        switch ($key) {
            case 'Jerry Coleman':
                $entity->setName('Oro Inc. Opportunity Name');
                $entity->setCustomer($customerRepo->getEntity('Jerry Coleman'));
                $entity->setDataChannel($channelRepo->getEntity('Sales channel|b2b'));
                $entity->setCreatedAt(new \DateTime());
                $entity->setUpdatedAt(new \DateTime());
                $entity->setOwner($userRepo->getEntity('John Doo'));
                $entity->setOrganization($organizationRepo->getEntity('default'));
                $budgetAmount = MultiCurrency::create(100000, 'USD');
                $entity->setBudgetAmount($budgetAmount);
                $entity->setContact($contactRepo->getEntity('Jerry Coleman'));
                $entity->setLead($leadRepo->getEntity('Jerry Coleman'));

                $statusName = 'in_progress';
                $className = ExtendHelper::buildEnumValueClassName(Opportunity::INTERNAL_STATUS_CODE);
                $id = ExtendHelper::buildEnumValueId($statusName);
                $entity->setStatus(new $className($id, $statusName));

                return;
        }

        parent::fillEntityData($key, $entity);
    }
}

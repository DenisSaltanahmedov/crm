<?php

namespace OroCRM\Bundle\MagentoBundle\ImportExport\Strategy;

use Doctrine\Common\Collections\Collection;

use Oro\Bundle\AddressBundle\Entity\AbstractAddress;
use Oro\Bundle\AddressBundle\Entity\AddressType;

use OroCRM\Bundle\AccountBundle\Entity\Account;
use OroCRM\Bundle\ContactBundle\Entity\Contact;
use OroCRM\Bundle\MagentoBundle\Entity\Address;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;
use OroCRM\Bundle\MagentoBundle\Entity\CustomerGroup;
use OroCRM\Bundle\MagentoBundle\Entity\Store;
use OroCRM\Bundle\MagentoBundle\Entity\Website;
use OroCRM\Bundle\MagentoBundle\Provider\MagentoConnectorInterface;

class CustomerStrategy extends BaseStrategy
{
    /** @var array */
    protected $storeEntityCache = [];

    /** @var array */
    protected $websiteEntityCache = [];

    /** @var array */
    protected $groupEntityCache = [];

    /**
     * Update/Create customer and related entities based on remote data
     *
     * @param Customer $remoteEntity Denormalized remote data
     *
     * @return Customer|null
     */
    public function process($remoteEntity)
    {
        /** @var Customer $localEntity */
        $localEntity = $this->getEntityByCriteria(
            ['originId' => $remoteEntity->getOriginId(), 'channel' => $remoteEntity->getChannel()],
            $remoteEntity
        );
        $localEntity = $localEntity ? : $remoteEntity;

        // update all related entities
        $this->updateStoresAndGroup(
            $localEntity,
            $remoteEntity->getStore(),
            $remoteEntity->getWebsite(),
            $remoteEntity->getGroup()
        );
        $this->updateContact($localEntity, $remoteEntity->getContact());
        $this->updateAccount($localEntity, $remoteEntity->getAccount());
        $localEntity->getAccount()->setDefaultContact($localEntity->getContact());

        // modify local entity after all relations done
        $this->strategyHelper->importEntity(
            $localEntity,
            $remoteEntity,
            ['id', 'contact', 'account', 'website', 'store', 'group', 'addresses']
        );
        $this->updateAddresses($localEntity, $remoteEntity->getAddresses());

        // validate and update context - increment counter or add validation error
        return $this->validateAndUpdateContext($localEntity);
    }

    /**
     * Update $entity with new data from imported $store, $website, $group
     *
     * @param Customer      $entity
     * @param Store         $store
     * @param Website       $website
     * @param CustomerGroup $group
     *
     * @return $this
     */
    protected function updateStoresAndGroup(Customer $entity, Store $store, Website $website, CustomerGroup $group)
    {
        // do not allow to change code/website name by imported entity
        $doNotUpdateFields = ['id', 'code', 'name'];

        if (!isset($this->websiteEntityCache[$website->getCode()])) {
            $this->websiteEntityCache[$website->getCode()] = $this->findAndReplaceEntity(
                $website,
                MagentoConnectorInterface::WEBSITE_TYPE,
                [
                'code'     => $website->getCode(),
                'channel'  => $website->getChannel(),
                'originId' => $website->getOriginId()
                ],
                $doNotUpdateFields
            );
        }
        $this->websiteEntityCache[$website->getCode()] = $this->merge($this->websiteEntityCache[$website->getCode()]);

        if (!isset($this->storeEntityCache[$store->getCode()])) {
            $this->storeEntityCache[$store->getCode()] = $this->findAndReplaceEntity(
                $store,
                MagentoConnectorInterface::STORE_TYPE,
                [
                'code'     => $store->getCode(),
                'channel'  => $store->getChannel(),
                'originId' => $store->getOriginId()
                ],
                $doNotUpdateFields
            );
        }
        $this->storeEntityCache[$store->getCode()] = $this->merge($this->storeEntityCache[$store->getCode()]);

        if (!isset($this->groupEntityCache[$group->getName()])) {
            $this->groupEntityCache[$group->getName()] = $this->findAndReplaceEntity(
                $group,
                MagentoConnectorInterface::CUSTOMER_GROUPS_TYPE,
                [
                'name'     => $group->getName(),
                'channel'  => $group->getChannel(),
                'originId' => $group->getOriginId()
                ],
                $doNotUpdateFields
            );
        }
        $this->groupEntityCache[$group->getName()] = $this->merge($this->groupEntityCache[$group->getName()]);

        $entity
            ->setWebsite($this->websiteEntityCache[$website->getCode()])
            ->setStore($this->storeEntityCache[$store->getCode()])
            ->setGroup($this->groupEntityCache[$group->getName()]);

        $entity->getStore()->setWebsite($entity->getWebsite());

        return $this;
    }

    /**
     * Update $entity with new contact data
     * updating contact data is not allowed
     *
     * @param Customer $entity
     * @param Contact  $contact
     *
     * @return $this
     */
    protected function updateContact(Customer $entity, Contact $contact)
    {
        // update not allowed
        if ($entity->getContact() && $entity->getContact()->getId()) {
            return $this;
        }

        // loop by imported addresses, add new only
        foreach ($contact->getAddresses() as $address) {
            // at this point imported address region have code equal to region_id in magento db field
            $mageRegionId = $address->getRegion() ? $address->getRegion()->getCode() : null;
            //$originAddressId = $address->getId();
            $address->setId(null);

            $this->updateAddressCountryRegion($address, $mageRegionId);
            if (!$address->getCountry()) {
                $contact->removeAddress($address);
                continue;
            }

            $this->updateAddressTypes($address);
        }

        $entity->setContact($contact);

        return $this;
    }

    /**
     * @param Customer             $entity
     * @param Collection|Address[] $addresses
     *
     * @return $this
     */
    protected function updateAddresses(Customer $entity, Collection $addresses)
    {
        // force option enforce re-import of all addresses
        if ($this->context->getOption('force') && $entity->getId()) {
            $entity->getAddresses()->clear();
        }

        /** $address - imported address */
        foreach ($addresses as $address) {
            // at this point imported address region have code equal to region_id in magento db field
            $mageRegionId = $address->getRegion() ? $address->getRegion()->getCode() : null;

            $originAddressId = $address->getId();
            $address->setOriginId($originAddressId);
            if ($originAddressId && !$this->context->getOption('force')) {
                $existingAddress = $entity->getAddressByOriginId($originAddressId);
                if ($existingAddress) {
                    $this->strategyHelper->importEntity(
                        $existingAddress,
                        $address,
                        ['id', 'region', 'country', 'created', 'updated']
                    );
                    // set remote data for further processing
                    $existingAddress->setRegion($address->getRegion());
                    $existingAddress->setCountry($address->getCountry());

                    $address = $existingAddress;
                }
            }

            $this->updateAddressCountryRegion($address, $mageRegionId);
            if (!$address->getCountry()) {
                $entity->removeAddress($address);
                continue;
            }

            $this->updateAddressTypes($address);

            $address->setOwner($entity);
            $entity->addAddress($address);
        }
    }

    /**
     * @param Customer $entity
     * @param Account  $account
     *
     * @return $this
     */
    protected function updateAccount(Customer $entity, Account $account)
    {
        /** @var Account $existingAccount */
        $existingAccount = $entity->getAccount();

        // update not allowed
        if ($existingAccount && $existingAccount->getId()) {
            return $this;
        }

        $addresses = [
            AddressType::TYPE_SHIPPING => $account->getShippingAddress(),
            AddressType::TYPE_BILLING  => $account->getBillingAddress()
        ];

        /** @var $address AbstractAddress|null */
        foreach ($addresses as $key => $address) {
            if (empty($address)) {
                continue;
            }

            $address->setId(null);
            $mageRegionId = $address->getRegion() ? $address->getRegion()->getCode() : null;
            $this->updateAddressCountryRegion($address, $mageRegionId);

            if ($address->getCountry()) {
                $account->{'set' . ucfirst($key) . 'Address'}($address);
            } else {
                $account->{'set' . ucfirst($key) . 'Address'}(null);
            }
        }
        $entity->setAccount($account);

        return $this;
    }
}

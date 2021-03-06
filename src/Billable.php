<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Stripe\Charge as StripeCharge;
use Stripe\Customer as StripeCustomer;
use Stripe\Error\InvalidRequest as StripeErrorInvalidRequest;
use Stripe\Invoice as StripeInvoice;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Refund as StripeRefund;
use Stripe\Source as StripeSource;
use Stripe\Token as StripeToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripeKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \Stripe\Error\Card
     */
    public function charge($amount, array $options = [])
    {
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['amount'] = $amount;

        if (!array_key_exists('source', $options) && $this->stripe_id)
        {
            $options['customer'] = $this->stripe_id;
        }

        if (!array_key_exists('source', $options) && !array_key_exists('customer', $options))
        {
            throw new InvalidArgumentException('No payment source provided.');
        }

        return StripeCharge::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param  string  $charge
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \Stripe\Error\Refund
     */
    public function refund($charge, array $options = [])
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        return (bool) $this->card_brand;
    }

    /**
     * Add an invoice item to the customer's upcoming invoice.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\InvoiceItem
     *
     * @throws \Stripe\Error\Card
     */
    public function tab($description, $amount, array $options = [])
    {
        if (!$this->stripe_id)
        {
            throw new InvalidArgumentException(class_basename($this) . ' is not a Stripe customer. See the createAsStripeCustomer method.');
        }

        $options = array_merge([
            'customer' => $this->stripe_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        return StripeInvoiceItem::create(
            $options, ['api_key' => $this->getStripeKey()]
        );
    }

    /**
     * Invoice the customer for the given amount and generate an invoice immediately.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return bool
     *
     * @throws \Stripe\Error\Card
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        $this->tab($description, $amount, $options);

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the Stripe model is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial())
        {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan))
        {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
        $subscription->stripe_plan === $plan;
    }

    /**
     * Determine if the Stripe model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Determine if the Stripe model has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription))
        {
            return false;
        }

        if (is_null($plan))
        {
            return $subscription->valid();
        }

        if ($subscription->valid() && $subscription->stripe_plan === $plan)
        {
            return true;
        }

        $plan = $this->subscriptionItem($plan);

        if (!is_null($plan) && $subscription->valid())
        {
            return true;
        }

        return false;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value)
        {
            return $value->created_at->getTimestamp();
        })
            ->first(function ($value) use ($subscription)
        {
                return $value->name === $subscription;
            });
    }

    /**
     * Get all of the subscriptions for the Stripe model.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return StripeInvoice|bool
     */
    public function invoice()
    {
        if ($this->stripe_id)
        {
            try {
                return StripeInvoice::create(['customer' => $this->stripe_id], $this->getStripeKey())->pay();
            }
            catch (StripeErrorInvalidRequest $e)
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return \Laravel\Cashier\Invoice|null
     */
    public function upcomingInvoice()
    {
        try {
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->stripe_id], ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        }
        catch (StripeErrorInvalidRequest $e)
        {
            //
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            return new Invoice($this, StripeInvoice::retrieve($id, $this->getStripeKey()));
        }
        catch (Exception $e)
        {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice))
        {
            throw new NotFoundHttpException;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array   $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeInvoices = $this->asStripeCustomer()->invoices($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (!is_null($stripeInvoices))
        {
            foreach ($stripeInvoices->data as $invoice)
            {
                if ($invoice->paid || $includePending)
                {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return new Collection($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Get a collection of the entity's cards.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function cards($parameters = [])
    {
        $cards = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeCards = $this->asStripeCustomer()->sources->all(
            ['object' => 'card'] + $parameters
        );

        if (!is_null($stripeCards))
        {
            foreach ($stripeCards->data as $card)
            {
                $cards[] = new Card($this, $card);
            }
        }

        return new Collection($cards);
    }

    /**
     * Update customer's credit card.
     *
     * @param  string  $token
     * @return void
     */
    public function updateCard($token)
    {
        $customer = $this->asStripeCustomer();
        $token = StripeToken::retrieve($token, ['api_key' => $this->getStripeKey()]);

        // If the given token already has the card as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same card to
        // a model's account every time we go through this particular method call.
        if ($token->card->id === $customer->default_source)
        {
            return;
        }

        $card = $customer->sources->create(['source' => $token]);

        $customer->default_source = $card->id;

        $customer->save();

        // Next we will get the default source for this model so we can update the last
        // four digits and the card brand on the record in the database. This allows
        // us to display the information on the front-end when updating the cards.
        $source = $customer->default_source
        ? $customer->sources->retrieve($customer->default_source)
        : null;

        $this->fillCardDetails($source);

        $this->save();
    }

    /**
     * Update customer's iban for SEPA.
     *
     * @param  string  $token
     * @return void
     */
    public function updateSepa($token)
    {
        $customer = $this->asStripeCustomer();
        $token = StripeSource::retrieve($token, ['api_key' => $this->getStripeKey()]);

        // If the given token already has the iban as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same iban to
        // a model's account every time we go through this particular method call.
        if ($token->id === $customer->default_source)
        {
            return;
        }

        $sepa = $customer->sources->create(['source' => $token]);

        $customer->default_source = $sepa->id;

        $customer->save();

        // Next we will get the default source for this model so we can update the sepa
        // informations in the database. This allows us to display the information on
        // the front-end when updating the iban.
        $source = $customer->default_source
        ? $customer->sources->retrieve($customer->default_source)
        : null;

        $this->fillSepaDetails($source);
        $this->save();
    }

    /**
     * Synchronises the customer's card from Stripe back into the database.
     *
     * @return $this
     */
    public function updateCardFromStripe()
    {
        $customer = $this->asStripeCustomer();

        $defaultCard = null;

        foreach ($customer->sources->data as $card)
        {
            if ($card->id === $customer->default_source)
            {
                $defaultCard = $card;
                break;
            }
        }

        if ($defaultCard)
        {
            $this->fillCardDetails($defaultCard)->save();
        }
        else
        {
            $this->forceFill([
                'card_brand' => null,
                'card_last_four' => null,
            ])->save();
        }

        return $this;
    }

    /**
     * Synchronises the customer's Sepa from Stripe back into the database.
     *
     * @return $this
     */
    public function updateSepaFromStripe()
    {
        $customer = $this->asStripeCustomer();

        $defaultSepa = null;

        foreach ($customer->sources->data as $sepa)
        {
            if ($sepa->id === $customer->default_source)
            {
                $defaultSepa = $sepa;
                break;
            }
        }

        if ($defaultSepa)
        {
            $this->fillSepaDetails($defaultSepa)->save();
        }
        else
        {
            $this->forceFill([
                'sepa_bank_code' => null,
                'sepa_country' => null,
                'sepa_fingerprint' => null,
                'sepa_last_four' => null,
                'sepa_mandate_reference' => null,
                'sepa_mandate_url' => null,
            ])->save();
        }

        return $this;
    }

    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param \Stripe\Card|null  $card
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        if ($card)
        {
            $this->card_brand = $card->brand;
            $this->card_last_four = $card->last4;
        }

        return $this;
    }

    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param \Stripe\Source|null  $sepa
     * @return $this
     */
    protected function fillSepaDetails($sepa)
    {
        if ($sepa && $sepa->sepa_debit)
        {
            $this->sepa_bank_code = $sepa->sepa_debit->bank_code;
            $this->sepa_country = $sepa->sepa_debit->country;
            $this->sepa_fingerprint = $sepa->sepa_debit->fingerprint;
            $this->sepa_last_four = $sepa->sepa_debit->last4;
            $this->sepa_mandate_reference = $sepa->sepa_debit->mandate_reference;
            $this->sepa_mandate_url = $sepa->sepa_debit->mandate_url;
        }

        return $this;
    }

    /**
     * Deletes the entity's cards.
     *
     * @return void
     */
    public function deleteCards()
    {
        $this->cards()->each(function ($card)
        {
            $card->delete();
        });
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the Stripe model is actively subscribed to one of the given plans.
     *
     * @param  array|string  $plans
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (!$subscription || !$subscription->valid())
        {
            return false;
        }

        foreach ((array) $plans as $plan)
        {
            if ($subscription->stripe_plan === $plan)
            {
                return true;
            }
            if ($subscription->hasItem($plan))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        $subscription = $this->subscriptionByPlan($plan);

        if (!is_null($subscription))
        {
            return $subscription->valid();
        }

        return false;
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        return !is_null($this->stripe_id);
    }

    /**
     * Create a Stripe customer for the given Stripe model.
     *
     * @param  string  $token
     * @param  array  $options
     * @return StripeCustomer
     */
    public function createAsStripeCustomer($token, array $options = [])
    {
        $options = array_key_exists('email', $options)
        ? $options : array_merge($options, ['email' => $this->email]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = StripeCustomer::create(
            $options, $this->getStripeKey()
        );

        $this->stripe_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (!is_null($token))
        {
            if (preg_match("/^src_(.*)/i", $token) > 0)
            {
                $this->updateSepa($token);
            }
            else
            {
                $this->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the Stripe model.
     *
     * @return \Stripe\Customer
     */
    public function asStripeCustomer()
    {
        return StripeCustomer::retrieve($this->stripe_id, $this->getStripeKey());
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getStripeKey()
    {
        if (static::$stripeKey)
        {
            return static::$stripeKey;
        }

        if ($key = getenv('STRIPE_SECRET'))
        {
            return $key;
        }

        return config('services.stripe.secret');
    }

    /**
     * Set the Stripe API key.
     *
     * @param  string  $key
     * @return void
     */
    public static function setStripeKey($key)
    {
        static::$stripeKey = $key;
    }

    /**
     * Begin creating a new multisubscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newMultisubscription($name = 'default')
    {
        return new MultisubscriptionBuilder($this, $name);
    }

    /**
     * Get all the subscription items for the user
     */
    public function subscriptionItems()
    {
        return $this->hasManyThrough(SubscriptionItem::class, Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Gets a subscription item instance by name.
     *
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionItem|null
     */
    public function subscriptionItem($plan)
    {
        $itemsTable = (new SubscriptionItem)->getTable();
        return $this->subscriptionItems()->where($itemsTable . '.stripe_plan', $plan)->orderBy($itemsTable . '.created_at', 'desc')->first();
    }

    /**
     * Adds a plan to the model's subscription
     *
     * @param string $plan The plan's ID
     * @param integer $quantity The plan's quantity
     * @param string $subscription The subscription's name
     * @return \Laravel\Cashier\Subscription
     */
    public function addPlan($plan, $prorate = true, $quantity = 1, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (!is_null($subscription))
        {
            return $subscription->addItem($plan, $prorate, $quantity);
        }

        return $this->newMultisubscription($subscription)->addPlan($plan, $prorate, $quantity)->create();
    }

    /**
     * Removes a plan from the model's subscription
     *
     * @param string $plan The plan's ID
     * @return \Laravel\Cashier\Subscription|null
     */
    public function removePlan($plan, $prorate = true, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription))
        {
            return null;
        }

        return $subscription->removeItem($plan, $prorate);
    }

    /**
     * Gets the subscription that contains the given plan
     *
     * @param string $plan The plan's ID
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscriptionByPlan($plan)
    {
        $subscription = $this->subscriptions()->where('stripe_plan', $plan)->first();

        if (!is_null($subscription))
        {
            return $subscription;
        }

        $item = $this->subscriptionItem($plan);

        if (is_null($item))
        {
            return null;
        }

        return $item->subscription;
    }
}

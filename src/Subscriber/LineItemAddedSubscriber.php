<?php declare(strict_types=1);

namespace PrintessEditor\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Framework\Uuid\Uuid;

class LineItemAddedSubscriber implements EventSubscriberInterface
{
    private RequestStack $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents(): array
    {
        return [BeforeLineItemAddedEvent::class => 'onLineItemAdded'];
    }

    public function onLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getLineItem();

        $request = $this->requestStack->getCurrentRequest();

        if($request != null)
        {
            $lineItems = $request->get('lineItems');

            if($lineItems != null)
            {
                foreach ($lineItems as $key => $item) {
                    if ($lineItem->getId() == $key) {
                        if (isset($item['_printessSaveToken'])) {
                            $lineItem->setPayloadValue('_printessSaveToken', $item['_printessSaveToken']);
                            $lineItem->setPayloadValue('_printessProduction', "");
                        }
            
                        if (isset($item['_printessThumbnailUrl'])) {
                            $lineItem->setPayloadValue('_printessThumbnailUrl', $item['_printessThumbnailUrl']);
                        }

                        if (isset($item['_printessProductOptions'])) {
                            $lineItem->setPayloadValue('_printessProductOptions', $item['_printessProductOptions']);
                        }
                    }
                }
            }
        }

        if($lineItem->getPayloadValue('_printessSaveToken') != null) {
            //Give a new id to the line item so it is not stacked
            $lineItem->setId(Uuid::randomHex());
        }
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Promotion\Cart\Discount\Composition;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;

class DiscountCompositionBuilder
{
    /**
     * @param DiscountCompositionItem[] $items
     */
    public function buildCompositionPayload(array $items): array
    {
        $payloadItems = [];

        /** @var DiscountCompositionItem $item */
        foreach ($items as $item) {
            $payloadItems[] = [
                'id' => $item->getId(),
                'quantity' => $item->getQuantity(),
                'discount' => $item->getDiscountValue(),
            ];
        }

        return $payloadItems;
    }

    /**
     * If our discount price is greater than our actual cart price, we have to
     * adjust the calculated discount price.
     * Due to that, we also have to adjust our composition data to match the new target price.
     */
    public function adjustCompositionItemValues(CalculatedPrice $targetPrice, array $targetItems): array
    {
        $compositionItems = [];

        /** @var DiscountCompositionItem $item */
        foreach ($targetItems as $item) {
            /** @var float $itemTotal */
            $itemTotal = $item->getDiscountValue();

            $factor = 0.0;

            if ($targetPrice->getTotalPrice() > 0) {
                /** @var float $factor */
                $factor = $itemTotal / $targetPrice->getTotalPrice();
            }

            $compositionItems[] = new DiscountCompositionItem(
                $item->getId(),
                $item->getQuantity(),
                abs($itemTotal) * $factor
            );
        }

        return $compositionItems;
    }
}

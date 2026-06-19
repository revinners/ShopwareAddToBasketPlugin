# Add to basket plugin for Shopware 6

The plugin provides a simple API endpoint to add products to the shopping cart.

If you'd like to learn more or work with us, visit our website at [revinners.com](https://revinners.com) for more information.

## Installation

```bash
composer require revinners/shopware6-add-to-basket-plugin # add the plugin to your Shopware project
./bin/console plugin:refresh # refresh the plugin list
./bin/console plugin:install --activate RevinnersAddToBasket # install and activate the plugin
```

## Usage

Call the API endpoint with the SKU and quantity of the product to add. The endpoint will return a JSON response.

**Endpoint**: `/add-to-basket`  
**Method**: `GET`  
**Parameters**:

- `sku` (string, required): The SKU (product number) of the product to add.
- `qty` (integer, required): The quantity of the product to add.

### Example Request

```
https://example.com/add-to-basket?sku=SW10001&qty=2
```

### Example Responses

```json
{
  "success": true,
  "message": "Product added to the basket"
}
```

```json
{
  "success": false,
  "message": "Product with SKU SW10001 not found"
}
```

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": ["SKU is required", "Quantity must be a positive integer"]
}
```

### Cart info

Returns a summary of the current session's cart (item count and totals). Read-only — it does not modify the cart.

**Endpoint**: `/cart-info`  
**Method**: `GET`  
**Parameters**: none

The cart is resolved from the current storefront **session cookie**, so the request must be sent **same-origin** with credentials (the cookie). This is the same consumption model as `/add-to-basket`.

#### Example Request

```js
const res = await fetch("/cart-info", {
  credentials: "same-origin",
  headers: { "X-Requested-With": "XMLHttpRequest" },
});
const data = await res.json();
```

#### Example Response

```json
{
  "success": true,
  "count": 5,
  "lineItemCount": 2,
  "totalPrice": "123.45",
  "netPrice": "100.36",
  "currencyId": "b7d2554b0ce847cd82f3ac9bd1c0dfca"
}
```

#### Response fields

| Field           | Type   | Description                                                                                                  |
| --------------- | ------ | ------------------------------------------------------------------------------------------------------------ |
| `success`       | bool   | Always `true` for a successful response.                                                                     |
| `count`         | int    | Total quantity of the eligible line items (sum of quantities) — the usual basket-badge number.               |
| `lineItemCount` | int    | Number of distinct line items in the cart (all types).                                                       |
| `totalPrice`    | string | **Gross** total of the eligible line items, formatted with 2 decimals and a `.` separator (e.g. `"123.45"`). |
| `netPrice`      | string | **Net** total of the eligible line items, same formatting as `totalPrice`.                                   |
| `currencyId`    | string | ID of the sales-channel currency the totals are expressed in.                                                |

> **Note:** the totals sum **all** line items regardless of type (a cart may hold several product-like types), **except** the battery deposit (`battery_deposit`) and promotions (`promotion`), which are left out of `count`, `totalPrice` and `netPrice`. The thresholds are meant to be compared against the value of the items themselves, so an applied discount must not lower it. Shipping/delivery costs are excluded implicitly because they are not line items. For an empty cart all numeric values are `0` / `"0.00"`.

## Testing

- `./vendor/bin/phpunit --configuration="custom/plugins/RevinnersAddToBasket" --color` - run the tests
- `./vendor/bin/ecs check src` - run the code style check
- `./vendor/bin/phpstan analyse src` - run the static analysis

## License

This plugin is licensed under the MIT License. You are free to use, modify, and distribute this software in accordance with the terms of the license.

For more details, see the [LICENSE](LICENSE) file included in this repository.

## About us

At Revinners, we specialize in building e-commerce shops and developing custom plugins based on the Shopware 6 platform. Our goal is to deliver efficient, scalable, and tailored solutions to meet the unique needs of online businesses.

If you'd like to learn more or work with us, visit our website at [revinners.com](https://revinners.com) for more information.

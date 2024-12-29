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
  "errors": [
    "SKU is required",
    "Quantity must be a positive integer"
  ]
}
```

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

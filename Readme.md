# Easy Order Manager

Add a short description here. You can also add a screenshot if needed.

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is EasyOrderManager.
* Activate it in your thelia administration panel

### Composer

Add it in your main thelia composer.json file

```
composer require your-vendor/easy-order-manager-module:~1.0
```

## Usage

Once activated, you will see a new menu link in Thelia's Back Office. This new page allows you to easly manage all orders
thanks to filters and search bars. This module uses Datables.

## Events

You can use 2 events to add filters to this module : 

```
BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER
TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD
```

In BeforeFilterEvent you have access to the order query and request.

In TemplateFieldEvent you can use the function addTemplateField(fieldName, templateName) 
to add a template with your new filter in it. You just need to add `js-filter-element` class to your filter input.
Make sure that fieldName is one of the column names defined in BackController defineColumnsDefinition() of this module. 
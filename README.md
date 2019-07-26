# Data Anonymizer for Neos Flow Applications

Anonymize sensitive data in your models via annotations

## Installation

Install the package via composer:
```
composer require aerticket/data-anonymizer
```
(or by adding the dependency to the composer manifest of an installed package)

## Basic usage

You can define which models should be anonymized via annotations:

```php
  use Aerticket\DataAnonymizer\Annotations as Anonymizer;
  
  /**
   * @Anonymizer\AnonymizableEntity(referenceDate="updatedAt")
   */
  class SomeClass {
     
     /**
      * @var \DateTime
      */
     $updatedAt;
     
     /**
      * @var string
      * @Anonymizer\Anonymize()
      */
     $personalData;
  }
```

The referenceDate option of the class annotation is mandatory. It is the path to the property that contains the date that
is used to determine the age of the entity. You can use the same syntax as a query condition (e.g.
`relatedObject.anotherRelatedObject.creationDate`).

To actually trigger the anonymization process, you have to call the following flow command:

```bash
./flow anonymize:run 
```

Please note that a maximum number of 100 entities is anonymized per class and run. If more entities have to be
anonymized, you have to run the command several times.

## Anonymize given entities
If you need to manually trigger data anonymization (e.g. user data that can be cleared after having been processed), you can explicitly anonymize a given entity:
```php
$this->anonymizationService->anonymizeEntity($record);
```
You can work on not (yet) persisted objects as well by avoiding an update of the record in it's repository with the second parameter to `anonymizeEntity`.

## Configuration options

### Age

You can define the age after which entities should be anonymized. The default value is configured via Settings.yaml:

```yaml
Aerticket:
  DataAnonymizer:
    defaults:
      anonymizeAfter: '30 days'
```

If you want to override this value for a specific class, you can use the `anonymizeAfter` option of the `AnonymizableEntity`
annotation.

### Anonymized values

When anonymizing an entity, a anonymized value is set for each property that should be anonymized. For different 
property types (string, integer), different default values are configured via Settings.yaml.

If you want to use an individual value for a specific property, you can add the value to the `Anonymize` annotation
of the property:

```php
  use Aerticket\DataAnonymizer\Annotations as Anonymizer;
  
  /**
   * @Anonymizer\AnonymizableEntity(referenceDate="updatedAt")
   */
  class SomeClass {
     
     /**
      * @var \DateTime
      */
     $updatedAt;
     
     /**
      * @var string
      * @Anonymizer\Anonymize("anonymized@anonymized.com")
      */
     $emailAddress;
  }
``` 

## Limitations

The following limitations apply at the moment:

- This package only works with entities that have its own repository.
- Anonymization of nested properties is non implemented yet.

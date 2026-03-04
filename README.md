# Migrator: Container content type builder

This TYPO3 extension extends the `migrator` extension by providing a content type builder for container content elements, helping you to migrate your existing content elements
to container elements.

## Installation

```bash
composer require webcoast/migrator-to-container
```

The extension has a dependency to the `migrator` extension, which will be installed automatically through composer. It also has a dependency to the `b13/container` extension, which also
will be installed automatically through composer.

**Important:** Remember to include `b13/container` in your `composer.json` file, to keep it installed after you have migrated your content elements and remove the migration related
extensions.

If you want to migrate flux elements to container elements you need the following packages:
* `b13/container`
* `webcoast/migrator-from-flux` (content type provider for flux content elements)
* `webcoast/migrator-to-container` (this extension)

If you want to migrate DCE elements to container elements, you need the following packages:
* `b13/container`
* `webcoast/migrator-from-dce` (content type provider for DCE content elements)
* `webcoast/migrator-to-container` (this extension)

## Compatibility

| Extension ↓ / TYPO3 → | 13.4 |
|-----------------------|:----:|
| 1.0.0                 |  ✅   |

## Content Type Builders

This extension provides a content type builder for container elements. It takes the normalized configuration provided by the content type provider and generates a file containing the
necessary TCA configuration for the container content element. It generates both the B13 Container configuration and eventual additional fields, that are displayed in the backend form
of the content element.

The TCA file is named after the chosen content type (CType), e.g. `3_images_with_text_container.php` and placed in the `Configuration/TCA/ContentTypes/` directory of the extension,
you choose to hold that content element (see documentation of the interactive wizard below). It automatically adds a `require_once` statement for the generated TCA file in the
`Configuration/TCA/Overrides/tt_content.php` file of the same extension.

Additionally, the builder tries to copy the template for the container element from the original content element, if provided by the content type provider. If not, no template is generated for the
container element, and you need to create a template yourself.

## Migration wizard
The interactive migration wizard guides you through the process of migrating your content elements to container elements. The process contains the following questions, you need to answer:
1. **In which extension, should we place the container content type?** This is auto-complete question with all installed extensions as options.
2. **What is the name/identifier of the content type?** You need to provide a valid CType value, e.g. `3_images_with_text_container`. Only characters, numbers and underscores are allowed.
3. **In which wizard category should the content element be placed?** This is auto-complete question with all existing wizard categories as options, but you can also provide a new category.
   However, the category will not be created/configured automatically, so you need to make sure, that the category you choose exists and is configured properly in the backend. The default
   value is the group value from the normalized content type configuration with `container` as fallback.

After answering these questions, the wizard walks through all fields in the normalized configuration and asks the following questions for each field:
1. **Do you want to process this field?** If not, skips this field. If yes, continues with the next question.
2. **What is the identifier of the field?** The field name, e.g. `image` or `faq_elements`. Only characters, numbers and underscores are allowed. The default value is derived from the
   field name in the normalized configuration.
3. **What is the label of the field?** The label of the field. The default value is label from the normalized configuration. This may be a language label starting with `LLL:`
4. **Do you want to use an existing field?** Yes or no. The default value depends on, if the chosen field name already exists in the TCA schema of the `tt_content` table.

Sections (anonymous inline records without a database table) and tabs are not supported.

After processing all fields, the builder generates the necessary TCA configuration for the container content element and places it in the chosen extension and adds the a `require_once`
statement for the generated TCA file in the `Configuration/TCA/Overrides/tt_content.php` file of the same extension.

For additional fields for the container element, both existing fields and new fields are supported. For existing fields, a columns overrides configuration is generated. For new fields,
the full field configuration is generated.

## Upgrade Wizard (Record data migration)

This extension provides the `ContainerAwareRecordMigrator` class, which can be extended by your custom record data migrator class to make migrating container elements a little easier.

The class provides the `moveIntoContainer($recordUid, $containerId, $colPos, $after)` method, which moves a content element into a container element.

The `$recordUid` is the ID of the current content element, which can be both a numeric UID or a `NEW...` placeholder for new records. The `$containerId` is the ID of the container element,
the current content element should be moved into. The `$colPos` is the column position within the container element, where the current content element should be placed. The `$after` is the
ID of the content element, after which the current content element should be placed. If `$after` is falsy, the current content element will be placed at the beginning of the column.

## Sponsors

The development of this extension has been sponsored by
* [Aemka](https://aemka.de/)
* [apart](https://apart.lu/)
* [HZ Internet Services](https://www.hziegenhain.de/)
* [Siteway](https://www.siteway.de/)

Thanks to all sponsors for their support and contributions to the development of this extension!

If you are interested in sponsoring the development of this extension, please contact me via email to [thorben@webcoast.dk](mailto:thorben@webcoast.dk) or in the TYPO3 Slack channel
(#ext-migrator).

## Contributing
Contributions to this extension are always welcome, both in form of pull requests, bug reports and feature requests and ideas.

If you have questions, reach out to me via email to [thorben@webcoast.dk](mailto:thorben@webcoast.dk), the discussion section of this repository or the TYPO3 Slack channel (#ext-migrator).

## License
This extension is licensed under the GPL-3.0 License. See the [LICENSE](LICENSE) file for more details.

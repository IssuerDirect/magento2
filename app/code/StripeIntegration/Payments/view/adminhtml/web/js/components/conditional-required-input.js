define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({
        defaults: {
            imports: {
                updateRequired: '${$.parentName}.sub_enabled:value'
            }
        },

        updateRequired: function (value) {
            var isRequired = value === true || value === 'true';
            this.required(isRequired);
            if (isRequired) {
                this.validation['required-entry'] = true;
                this.validation['validate-greater-than-zero'] = true;
            } else {
                delete this.validation['required-entry'];
                delete this.validation['validate-greater-than-zero'];
            }
        }
    });
});

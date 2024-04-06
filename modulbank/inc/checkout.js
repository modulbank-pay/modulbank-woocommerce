const modulbank_settings = window.wc.wcSettings.getSetting( 'modulbank_data', {} );
const modulbank_label = window.wp.htmlEntities.decodeEntities( modulbank_settings.title ) || window.wp.i18n.__( 'Modulbank', 'modulbank_payment' );
const ModulbankContent = () => {
    return window.wp.htmlEntities.decodeEntities( modulbank_settings.description || '' );
};
const Modulbank_Block_Gateway = {
    name: 'modulbank',
    label: modulbank_label,
    content: Object( window.wp.element.createElement )( ModulbankContent, null ),
    edit: Object( window.wp.element.createElement )( ModulbankContent, null ),
    canMakePayment: () => true,
    ariaLabel: modulbank_label,
    supports: {
        features: modulbank_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Modulbank_Block_Gateway );
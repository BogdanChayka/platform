import './component';
import './preview';

const { Application } = Shopware;

Application.getContainer('service').cmsService.registerCmsBlock({
    name: 'image-two-column',
    label: 'Two column image grid',
    category: 'image',
    component: 'sw-cms-block-image-two-column',
    previewComponent: 'sw-cms-preview-image-two-column',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
        sizingMode: 'boxed'
    },
    slots: {
        left: {
            type: 'image',
            default: {
                config: {
                    displayMode: { source: 'static', value: 'cover' }
                },
                data: {
                    media: {
                        url: '/administration/static/img/cms/preview_camera_large.jpg'
                    }
                }
            }
        },
        right: {
            type: 'image',
            default: {
                config: {
                    displayMode: { source: 'static', value: 'cover' }
                },
                data: {
                    media: {
                        url: '/administration/static/img/cms/preview_plant_large.jpg'
                    }
                }
            }
        }
    }
});

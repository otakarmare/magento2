/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
define([
        'Magento_Ui/js/lib/component/links'
    ], function (links) {
        'use strict';

        describe( 'Magento_Ui/js/lib/component/links', function(){
            var linksObj,
                returnedValue;

            beforeEach(function(){
                linksObj = links;
                linksObj.maps = {
                    exports: {},
                    imports: {}
                };

            });
            it('has defaults', function(){
                expect(typeof linksObj.defaults).toEqual('object');
            });
            it('has setLinks method', function(){
                returnedValue = linksObj.setLinks(undefined,'imports');
                expect(typeof returnedValue).toEqual('object');
                spyOn(linksObj, "setLinks");
                linksObj.setLinks(undefined,'imports');
                expect(linksObj.setLinks).toHaveBeenCalled();
            });
            it('has setListners method', function(){
                spyOn(linksObj, "setListners");
                linksObj.setListners();
                expect(linksObj.setListners).toHaveBeenCalled();
            });
        });
    });

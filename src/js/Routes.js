Ext.define('TualoOffice.routes.Skeleton',{
    url: 'skeleton',
    handler: {
        action: function(token){
            console.log('onAnyRoute',token);
            alert('skeleton','ok');
        },
        before: function (action) {
            console.log('onBeforeToken',action);
            console.log(new Date());
            action.resume();
        }
    }
});
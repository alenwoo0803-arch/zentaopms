/**
 * 执行钉钉通讯录同步。
 * Run DingTalk org sync.
 *
 * @access public
 * @return void
 */
function syncOrgSync()
{
    var $result = $('#orgsyncResult');
    var $button = $('#orgsyncSyncBtn');
    if($button.hasClass('disabled')) return;

    $button.addClass('disabled');
    $result.html('<div class="text-gray">' + orgsyncLang.syncing + '</div>');

    $.ajax
    ({
        url: $.createLink('orgsync', 'sync'),
        type: 'POST',
        dataType: 'json',
        success: function(response)
        {
            if(response.result == 'success')
            {
                $result.html('<div class="text-success">' + response.message + '</div>');
                setTimeout(function(){ loadPage($.createLink('orgsync', 'index')); }, 1200);
            }
            else
            {
                $result.html('<div class="text-danger">' + (response.message || orgsyncLang.requestFailed) + '</div>');
            }
        },
        error: function()
        {
            $result.html('<div class="text-danger">' + orgsyncLang.requestFailed + '</div>');
        },
        complete: function()
        {
            $button.removeClass('disabled');
        }
    });
}

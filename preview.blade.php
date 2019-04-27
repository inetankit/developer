@extends('layouts.master')

@section('content')
    <h1>Preview New Waybill</h1>

    <img src="/images/temp/waybills/{{ $preview_image }}">

    <div class="alert alert-warning text-center" role="alert" style="width: 1154px;">Your waybill has not yet been created. Click Submit waybill to finish.</div>

    <form class="form" id="store_waybill" action="{{ url('/waybills') }}" method="post">
        <div>
            @if ($quote_id !== null)
            <a href="{{ url('waybills/convert/' . $quote_id) }}" class="btn btn-default btn-lg">Edit waybill</a>
            <input type="hidden" name="quote_id" value="{{ $quote_id }}">
            @else
            <a href="{{ url('waybills/create') }}" class="btn btn-default btn-lg">Edit waybill</a>
            @endif
            {!! csrf_field() !!}
            <button type="submit" class="btn btn-primary btn-lg">Submit waybill</button>
            <span id="notify" style="padding-left: 20px; padding-top: 20px; padding-bottom: 20px;">Would you like to be notified regarding discrepancies greater than 5%?
                <label style="padding-left: 10px">
                    <input type="radio" name="notifyDiscrepancies" id="notifyDiscrepanciesYes" value="1">
                    Yes
                </label>
                <label style="padding-right: 20px;">
                    <input type="radio" name="notifyDiscrepancies" id="notifyDiscrepanciesNo" value="0">
                    No
                </label>
            
            </span>
            
            
             @if( $international_service_exist ) 
            <span id="mail_print_notify" style="padding-left: 20px; display:inline-block; padding-top: 20px; padding-bottom: 20px;">
	          <input type="checkbox" name="mail_print" id="mail_print" value="1" /> <label for="mail_print"> Mail must be printed in Alphabetical country order or additional fees may apply.</label>
            </span>
            @endif 
            
            
            
        </div>
    </form>
@endsection

@section('pagescript')
    <script>
        $(document).ready(function () {
            $('#store_waybill').submit(function (event) {
				
				var error = 0;

                if ($('input[name=notifyDiscrepancies]:checked').length == 0) {
                    $('#notify').css('background',  '#FF0');
                	error++;
                } else {
					$('#notify').css('background',  '');

				}

				if ($('#mail_print_notify').is(':visible') && $('input[name=mail_print]:checked').length == 0) {
                    $('#mail_print_notify').css('background',  '#FF0');
                    error++;
                }else {
					$('#mail_print_notify').css('background',  '');
				}

				if(error>0) {
					event.preventDefault();
                    return false;
				}

            });
        });
    </script>
@endsection

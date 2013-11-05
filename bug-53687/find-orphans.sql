select up_page,up_timestamp,log_namespace,log_title,rev_id,ar_rev_id is not null as ar_rev_match,rev_text_id=ar_text_id as ar_text_match from revision left join archive on ar_rev_id=rev_id ,updates left join logging on up_timestamp=log_timestamp  where log_action='delete' and rev_page=up_page and up_action='delete';

